"use strict";
(() => {
  const root = document.getElementById("app");
  if (!root) return;
  const cfg = JSON.parse(root.dataset.config);
  const word = (key) => cfg.words[key] || key;
  const $ = (id) => document.getElementById(id);
  const profileForm = $("profile-edit-form");
  const profileEditToggle = $("profile-edit-toggle");
  const profileEditCancel = $("profile-edit-cancel");
  const replyPreview = $("reply-preview");
  const replyTitle = $("reply-title");
  const replyText = $("reply-text");
  let replyTarget = null;
  const searchIndicator = $("search-indicator") || (() => {
    const indicator = document.createElement("span");
    indicator.id = "search-indicator";
    indicator.className = "search-indicator";
    indicator.setAttribute("aria-hidden", "true");
    indicator.hidden = true;
    for (let i = 0; i < 3; i++) indicator.append(document.createElement("span"));
    $("peer").before(indicator);
    return indicator;
  })();
  const chatHeader = $("chat-header") || root.querySelector(".chat-header");
  let state = "guest",
    chatId = 0,
    lastChat = 0,
    cursor = 0,
    seen = new Set();
  let polling = false,
    timer = null,
    transport = cfg.transport,
    failures = 0;
  let typingAt = 0,
    pending = null,
    readSent = 0,
    queueAt = 0,
    leavingChat = false;
  let currentWarning = "";
  let editableProfile = null;

  let initialState = true,
    soundsEnabled = true,
    audio = null;
  try {
    soundsEnabled = localStorage.getItem("kg-sounds") !== "off";
  } catch (_) {}
  const labels =
    cfg.lang === "ky"
      ? {
          hide: "Чыпкаларды жашыруу",
          show: "Чыпкаларды көрсөтүү",
          on: "Үн: күйүк",
          off: "Үн: өчүк",
          loading: "Жүктөлүүдө…",
        }
      : {
          hide: "Скрыть фильтры",
          show: "Показать фильтры",
          on: "Звук: вкл.",
          off: "Звук: выкл.",
          loading: "Загрузка…",
        };
  // Не показываем пустую анкету, пока сервер восстанавливает текущую сессию.
  $("entry").hidden = true;
  const loading = $("session-loading") || document.createElement("p");
  loading.id = "session-loading";
  loading.className = "muted";
  loading.textContent = labels.loading;
  loading.setAttribute("role", "status");
  loading.hidden = false;
  if (!loading.isConnected) root.prepend(loading);
  const sidebar = root.querySelector(".filters");
  const shell = document.createElement("div");
  shell.className = "filter-shell";
  shell.id = "search-filters";
  const inner = document.createElement("div");
  inner.className = "filter-inner";
  sidebar.before(shell);
  shell.append(inner);
  inner.append(sidebar);
  const toolbar = document.createElement("div");
  toolbar.className = "chat-tools";
  const filterToggle = document.createElement("button");
  filterToggle.type = "button";
  filterToggle.className = "secondary";
  filterToggle.setAttribute("aria-controls", shell.id);
  const soundToggle = document.createElement("button");
  soundToggle.type = "button";
  soundToggle.className = "secondary";
  toolbar.append(filterToggle, soundToggle);
  root.querySelector(".chat-header").append(toolbar);
  const collapseFilters = (collapsed) => {
    if (collapsed && shell.contains(document.activeElement))
      filterToggle.focus();
    $("workspace").classList.toggle("filters-collapsed", collapsed);
    filterToggle.setAttribute("aria-expanded", String(!collapsed));
    filterToggle.textContent = collapsed ? labels.show : labels.hide;
    shell.setAttribute("aria-hidden", String(collapsed));
    // inert поддерживается современными браузерами; tabindex — запасной вариант.
    shell.inert = collapsed;
    shell
      .querySelectorAll("input, select, button, a, textarea")
      .forEach((element) => {
        if (collapsed) {
          if (!element.hasAttribute("data-old-tabindex"))
            element.dataset.oldTabindex =
              element.getAttribute("tabindex") ?? "";
          element.setAttribute("tabindex", "-1");
        } else if (element.hasAttribute("data-old-tabindex")) {
          if (element.dataset.oldTabindex === "")
            element.removeAttribute("tabindex");
          else element.setAttribute("tabindex", element.dataset.oldTabindex);
          delete element.dataset.oldTabindex;
        }
      });
  };
  collapseFilters(false);
  filterToggle.addEventListener("click", () =>
    collapseFilters(!$("workspace").classList.contains("filters-collapsed")),
  );
  const updateSoundButton = () => {
    soundToggle.textContent = soundsEnabled ? labels.on : labels.off;
    soundToggle.setAttribute("aria-pressed", String(soundsEnabled));
  };
  const unlockAudio = () => {
    try {
      const Audio = window.AudioContext || window.webkitAudioContext;
      if (!Audio || !soundsEnabled) return;
      if (!audio) audio = new Audio();
      if (audio.state === "suspended") audio.resume().catch(() => {});
    } catch (_) {}
  };
  document.addEventListener("pointerdown", unlockAudio, { passive: true });
  document.addEventListener("keydown", unlockAudio);
  soundToggle.addEventListener("click", () => {
    soundsEnabled = !soundsEnabled;
    try {
      localStorage.setItem("kg-sounds", soundsEnabled ? "on" : "off");
    } catch (_) {}
    updateSoundButton();
    unlockAudio();
  });
  updateSoundButton();
  // Локальные короткие сигналы: не нужны аудиофайлы и внешние сервисы.
  const playSound = (incoming) => {
    if (!soundsEnabled || !audio || audio.state !== "running") return;
    try {
      const start = audio.currentTime;
      const oscillator = audio.createOscillator(),
        gain = audio.createGain();
      oscillator.type = "sine";
      oscillator.frequency.setValueAtTime(incoming ? 740 : 480, start);
      oscillator.frequency.exponentialRampToValueAtTime(
        incoming ? 990 : 600,
        start + 0.08,
      );
      gain.gain.setValueAtTime(0.0001, start);
      gain.gain.exponentialRampToValueAtTime(0.06, start + 0.012);
      gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.14);
      oscillator.connect(gain);
      gain.connect(audio.destination);
      oscillator.start(start);
      oscillator.stop(start + 0.15);
      oscillator.onended = () => {
        oscillator.disconnect();
        gain.disconnect();
      };
    } catch (_) {}
  };

  const notifyMatch = () => {
    const text =
      cfg.lang === "ky"
        ? "Сүйлөшүүчү табылды. Саламдашыңыз 👋"
        : "Собеседник найден. Поздоровайтесь 👋";

    notice(text);

    const header = root.querySelector(".chat-header");
    header.classList.remove("match-found");
    void header.offsetWidth;
    header.classList.add("match-found");

    setTimeout(() => {
      header.classList.remove("match-found");

      if ($("notice").textContent === text) {
        notice("");
      }
    }, 3500);

    if (!soundsEnabled || !audio || audio.state !== "running") return;

    try {
      [523.25, 659.25, 783.99].forEach((frequency, index) => {
        const start = audio.currentTime + index * 0.11;
        const oscillator = audio.createOscillator();
        const gain = audio.createGain();

        oscillator.type = "sine";
        oscillator.frequency.setValueAtTime(frequency, start);

        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.07, start + 0.015);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.22);

        oscillator.connect(gain);
        gain.connect(audio.destination);
        oscillator.start(start);
        oscillator.stop(start + 0.24);

        oscillator.onended = () => {
          oscillator.disconnect();
          gain.disconnect();
        };
      });
    } catch (_) {}
  };

  const randomId = () => {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
  };
  const device = () => {
    let id;
    try {
      id = localStorage.getItem("kg-device");
    } catch (_) {}
    if (!id || !/^[a-f0-9]{32}$/.test(id)) {
      id = randomId();
      try {
        localStorage.setItem("kg-device", id);
      } catch (_) {}
    }
    return id;
  };
  const deviceId = device();
  const notice = (text, error = false) => {
    $("notice").textContent = text;
    $("notice").hidden = !text;
    $("notice").classList.toggle("danger", error);
  };
  const captcha = (challenge) =>
    new Promise((resolve) => {
      const dialog = $("captcha-dialog");
      $("captcha-question").textContent = challenge.question;
      $("captcha-answer").value = "";
      const close = () => {
        cleanup();
        resolve(null);
      };
      const submit = (event) => {
        event.preventDefault();
        const answer = $("captcha-answer").value;
        cleanup();
        dialog.close();
        resolve({ captcha_token: challenge.token, captcha_answer: answer });
      };
      const cleanup = () => {
        dialog.removeEventListener("close", close);
        $("captcha-form").removeEventListener("submit", submit);
      };
      dialog.addEventListener("close", close, { once: true });
      $("captcha-form").addEventListener("submit", submit);
      dialog.showModal();
      $("captcha-answer").focus();
    });
  const api = async (route, data = null, retry = true) => {
    const controller = new AbortController();
    const limit = setTimeout(
      () => controller.abort(),
      route.startsWith("state") ? 27000 : 15000,
    );
    try {
      const response = await fetch(`${cfg.base}/api/${route}`, {
        method: data === null ? "GET" : "POST",
        credentials: "same-origin",
        cache: "no-store",
        signal: controller.signal,
        headers: {
          "X-CSRF-Token": cfg.csrf,
          ...(data === null ? {} : { "Content-Type": "application/json" }),
        },
        body: data === null ? undefined : JSON.stringify(data),
      });
      const result = await response.json();
      if (!response.ok || result.error) {
        if (result.captcha && data !== null && retry) {
          const solved = await captcha(result.captcha);
          if (solved) return api(route, { ...data, ...solved }, false);
        }
        throw new Error(result.error || word("server_error"));
      }
      return result;
    } catch (error) {
      if (error instanceof TypeError || error.name === "AbortError")
        throw new Error(word("network"));
      throw error;
    } finally {
      clearTimeout(limit);
    }
  };
  const filters = () => {
    const form = $("filter-form");
    return {
      city: form.elements.city.value,
      gender: form.elements.gender.value,
      min_age: form.elements.min_age.value,
      max_age: form.elements.max_age.value,
    };
  };
  const person = (p) =>
    `${p.nickname} · ${word(p.gender)} · ${p.age} · ${p.city}`;
  const receipts = (data) => {
    document.querySelectorAll(".bubble.mine").forEach((element) => {
      const id = Number(element.dataset.id);
      element.querySelector(".receipt").textContent =
        id <= data.peer_read
          ? word("read")
          : id <= data.peer_delivered
            ? word("delivered")
            : word("sent");
    });
  };
  const updateMessageReaction = (article, count) => {
    const reaction = article.querySelector(".message-reaction");
    if (!reaction) return;
    const show = Number(count) > 0;
    if (reaction.hidden && show) {
      reaction.hidden = false;
      reaction.classList.remove("reaction-pop");
      void reaction.offsetWidth;
      reaction.classList.add("reaction-pop");
    } else if (!show) {
      reaction.hidden = true;
      reaction.classList.remove("reaction-pop");
    }
  };
  const pendingLikes = new Set();
  let lastTouchLikeAt = 0;
  const toggleMessageLike = async (article) => {
    const messageId = Number(article.dataset.id);
    if (state !== "chat" || !messageId || pendingLikes.has(messageId)) return;
    pendingLikes.add(messageId);
    try {
      const result = await api("like", { chat_id: chatId, message_id: messageId });
      updateMessageReaction(article, result.like_count);
      refresh();
    } catch (error) {
      notice(error.message, true);
    } finally {
      pendingLikes.delete(messageId);
    }
  };
  const clearReplyTarget = () => {
    replyTarget = null;
    if (replyPreview) replyPreview.hidden = true;
    if (replyTitle) replyTitle.textContent = "";
    if (replyText) replyText.textContent = "";
  };
  const selectReplyTarget = (article) => {
    const text = article.querySelector(":scope > p")?.textContent || "";
    replyTarget = {
      id: Number(article.dataset.id),
      mine: article.classList.contains("mine"),
      body: text,
    };
    if (replyTitle) replyTitle.textContent = word(replyTarget.mine ? "reply_to_you" : "reply_to_peer");
    if (replyText) replyText.textContent = replyTarget.body;
    if (replyPreview) replyPreview.hidden = false;
    $("message").focus();
  };
  const addReplyQuote = (article, reply) => {
    if (!reply || typeof reply.body !== "string") return;
    const quote = document.createElement("div");
    quote.className = "reply-quote";
    const author = document.createElement("strong");
    author.textContent = word(reply.mine ? "reply_to_you" : "reply_to_peer");
    const excerpt = document.createElement("span");
    excerpt.textContent = reply.body;
    quote.append(author, excerpt);
    article.prepend(quote);
  };
  const markRead = async () => {
    if (state !== "chat" || document.hidden || cursor <= readSent) return;
    const id = cursor;
    try {
      await api("read", { chat_id: chatId, id });
      readSent = id;
    } catch (_) {}
  };
  const showPeerLeft = () => {
    const ended = document.createElement("div");
    ended.className = "chat-system-message";
    ended.textContent = word("peer_left");
    $("messages").append(ended);
  };
  const fillProfileForm = (profile) => {
    if (!profile || !profileForm) return;
    profileForm.elements.nickname.value = profile.nickname === word("anonymous") ? "" : profile.nickname;
    profileForm.elements.gender.value = profile.gender;
    profileForm.elements.age.value = profile.age;
    profileForm.elements.city.value = String(profile.city_id);
  };
  const render = (data) => {
    const previous = state;
    const restoring = initialState;
    initialState = false;
    loading.remove();
    state = data.state;
    $("live-dot").hidden = state !== "chat";
    searchIndicator.hidden = state !== "queue";
    chatHeader?.classList.toggle("is-searching", state === "queue");
    if (state === "chat" && previous !== "chat") collapseFilters(true);
    else if (previous === "chat" && state !== "chat") collapseFilters(false);
    if (state === "guest" || state === "expired") {
      clearReplyTarget();
      $("entry").hidden = false;
      $("workspace").hidden = true;
      chatId = 0;
      return;
    }
    $("entry").hidden = true;
    $("workspace").hidden = false;
    if (data.me) $("me").textContent = person(data.me);
    if (data.editable_profile && profileForm) {
      editableProfile = data.editable_profile;
      if (profileForm.hidden) fillProfileForm(editableProfile);
    }
    $("online").textContent = data.online ?? "—";
    $("queued").textContent = data.queued ?? labels.loading;
    $("state").textContent = word(state);
    $("typing").textContent = data.typing ? word("typing") : "";
    $("search").disabled = ["chat", "queue", "banned", "maintenance"].includes(
      state,
    );
    $("cancel").hidden = state !== "queue";
    $("next").disabled = ["banned", "maintenance"].includes(state);
    $("end").disabled = state !== "chat";
    $("message").disabled = state !== "chat";
    $("send").disabled = state !== "chat";
    if (state === "queue") {
      queueAt = Date.parse(data.queue.joined_at.replace(" ", "T") + "Z");
      $("peer").textContent = word("queue");
    } else {
      queueAt = 0;
      $("expand").hidden = true;
    }
    if (state === "chat") {
      if (chatId !== data.chat_id) {
        const peerMovedOn =
          previous === "chat" && chatId && !leavingChat;
        if (!restoring) notifyMatch();

        chatId = data.chat_id;
        leavingChat = false;
        lastChat = chatId;
        cursor = 0;
        readSent = 0;
        seen = new Set();
        pending = null;
        $("message").value = "";
        $("messages").replaceChildren();
        clearReplyTarget();
        if (peerMovedOn) showPeerLeft();
      }
      $("peer").textContent = person(data.peer);
      let receivedNew = false;
      for (const item of data.messages || []) {
        if (seen.has(item.id)) continue;
        if (!item.mine && !restoring) receivedNew = true;
        seen.add(item.id);
        cursor = Math.max(cursor, item.id);
        const article = document.createElement("article");
        article.className = `bubble${item.mine ? " mine" : ""}${!restoring ? " bubble-send" : ""}`;
        article.dataset.id = item.id;
        const text = document.createElement("p");
        text.textContent = item.body;
        addReplyQuote(article, item.reply);
        const meta = document.createElement("small");
        const time = document.createElement("time");
        const date = new Date(item.created_at.replace(" ", "T") + "Z");
        time.dateTime = date.toISOString();
        time.textContent = date.toLocaleTimeString(
          cfg.lang === "ky" ? "ky-KG" : "ru-RU",
          { hour: "2-digit", minute: "2-digit" },
        );
        meta.append(time);
        if (item.mine) {
          const span = document.createElement("span");
          span.className = "receipt";
          meta.append(span);
        }
        article.append(text, meta);
        const reaction = document.createElement("span");
        reaction.className = "message-reaction";
        reaction.setAttribute("role", "img");
        reaction.setAttribute("aria-label", word("liked"));
        reaction.textContent = "❤️";
        reaction.hidden = !(Number(item.like_count) > 0);
        article.append(reaction);
        $("messages").append(article);
      }
      for (const item of data.like_updates || []) {
        const article = $("messages").querySelector(`.bubble[data-id="${Number(item.id)}"]`);
        if (article) updateMessageReaction(article, item.like_count);
      }
      if (receivedNew) playSound(true);
      // Ограничиваем DOM для долгого разговора, курсор сохраняется.
      while ($("messages").children.length > 300)
        $("messages").firstElementChild.remove();
      receipts(data);
      if ((data.messages || []).length)
        $("messages").scrollTop = $("messages").scrollHeight;
      markRead();
    } else {
      if (state !== "chat" && replyTarget) clearReplyTarget();
      if (
        previous === "chat" &&
        state === "idle" &&
        ["left", "next"].includes(data.end_reason) &&
        !leavingChat
      ) {
        showPeerLeft();
        $("messages").scrollTop = $("messages").scrollHeight;
      }
      if (state !== "queue") leavingChat = false;
      chatId = 0;
      if (state === "idle") {
        $("peer").textContent =
          previous === "queue"
            ? word("queue_expired")
            : previous === "chat"
              ? word("chat_ended")
              : word("idle");
      }
    }
    $("report").disabled =
      !lastChat || ["banned", "maintenance"].includes(state);
    if (state === "banned" || state === "maintenance")
      notice(data.reason || word(state), true);
    if (data.warning && data.warning !== currentWarning) {
      currentWarning = data.warning;
      notice(data.warning, true);
      api("warning", {}).catch(() => {});
    }
  };
  const poll = async () => {
    if (polling) return;
    polling = true;
    try {
      const data = await api(
        `state?cursor=${cursor}&transport=${transport === "long" ? "long" : "poll"}&message_ids=${[...document.querySelectorAll("#messages .bubble[data-id]")].map((element) => element.dataset.id).join(",")}`,
      );
      failures = 0;
      render(data);
    } catch (error) {
      failures++;
      transport = "poll";
      notice(error.message, true);
      loading.textContent = labels.loading;
    } finally {
      polling = false;
      clearTimeout(timer);
      const wait =
        transport === "long" && ["chat", "queue"].includes(state) && !failures
          ? 100
          : Math.min(15000, cfg.poll * 1000 * Math.max(1, failures));
      timer = setTimeout(poll, wait);
    }
  };
  const refresh = () => {
    clearTimeout(timer);
    if (!polling) poll();
  };
  const act = async (route, data = {}) => {
    try {
      const result = await api(route, data);
      notice("");
      refresh();
      return result;
    } catch (error) {
      notice(error.message, true);
      return null;
    }
  };
  const startSearch = () => {
    if (state === "chat") leavingChat = true;
    $("messages").replaceChildren();
  };
  $("entry-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget,
      submit = form.querySelector("button");
    submit.disabled = true;
    const data = Object.fromEntries(new FormData(form));
    data.device = deviceId;
    await act("entry", data);
    submit.disabled = false;
  });
  if (profileForm && profileEditToggle && profileEditCancel) {
    profileEditToggle.addEventListener("click", () => {
      profileForm.hidden = !profileForm.hidden;
      profileEditToggle.setAttribute("aria-expanded", String(!profileForm.hidden));
      if (!profileForm.hidden) fillProfileForm(editableProfile);
    });
    profileEditCancel.addEventListener("click", () => {
      fillProfileForm(editableProfile);
      profileForm.hidden = true;
      profileEditToggle.setAttribute("aria-expanded", "false");
    });
    profileForm.addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const submit = form.querySelector('button[type="submit"]');
      submit.disabled = true;
      try {
        await api("profile", Object.fromEntries(new FormData(form)));
        profileForm.hidden = true;
        profileEditToggle.setAttribute("aria-expanded", "false");
        notice(word("profile_saved"));
        refresh();
      } catch (error) {
        notice(error.message, true);
      } finally {
        submit.disabled = false;
      }
    });
  }
  $("filter-form").addEventListener("submit", (event) => {
    event.preventDefault();
    startSearch();
    act("join", filters());
  });
  $("cancel").addEventListener("click", () => act("leave"));
  $("expand").addEventListener("click", () => {
    startSearch();
    const form = $("filter-form");
    form.elements.city.value = "0";
    form.elements.gender.value = "";
    form.elements.min_age.value = cfg.minAge;
    form.elements.max_age.value = cfg.maxAge;
    act("join", filters());
  });
  $("next").addEventListener("click", () => {
    if ($("filter-form").reportValidity()) {
      startSearch();
      act("next", filters());
    }
  });
  $("end").addEventListener("click", () => {
    if (state === "chat") leavingChat = true;
    act("leave");
  });
  $("forget").addEventListener("click", async () => {
    if (confirm(word("forget_confirm"))) {
      const result = await act("forget");
      if (result) {
        lastChat = 0;
        cursor = 0;
        $("messages").replaceChildren();
        render({ state: "guest" });
      }
    }
  });
  $("send-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const body = $("message").value.trim();
    if (!body || state !== "chat") return;
    if (
      !pending ||
      pending.body !== body ||
      pending.chat_id !== chatId ||
      pending.reply_to_id !== (replyTarget?.id || 0)
    )
      pending = {
        chat_id: chatId,
        body,
        nonce: randomId(),
        reply_to_id: replyTarget?.id || 0,
      };
    $("send").disabled = true;
    const result = await act("send", pending);
    if (result && result.ok && result.id) {
      playSound(false);
      $("message").value = "";
      pending = null;
      clearReplyTarget();
    }
    $("send").disabled = state !== "chat";
  });
  $("messages").addEventListener("dblclick", (event) => {
    if (Date.now() - lastTouchLikeAt < 500) return;
    const article =
      event.target instanceof Element
        ? event.target.closest(".bubble[data-id]")
        : null;
    if (!article) return;
    event.preventDefault();
    toggleMessageLike(article);
  });
  let lastTouch = null;
  let dragReply = null;
  $("messages").addEventListener("pointerdown", (event) => {
    const article = event.target instanceof Element
      ? event.target.closest(".bubble[data-id]")
      : null;
    if (
      event.pointerType === "mouse" &&
      (event.button !== 0 || event.target.closest("a, button, input, textarea, select"))
    ) return;
    if (!article || state !== "chat") return;
    const now = Date.now();
    if (event.pointerType === "touch" &&
      article &&
      lastTouch?.article === article &&
      now - lastTouch.at < 400 &&
      Math.hypot(event.clientX - lastTouch.x, event.clientY - lastTouch.y) < 32
    ) {
      event.preventDefault();
      lastTouchLikeAt = now;
      lastTouch = null;
      toggleMessageLike(article);
      return;
    }
    if (event.pointerType === "touch") {
      lastTouch = { article, at: now, x: event.clientX, y: event.clientY };
    }
    dragReply = {
      article,
      pointerId: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      dx: 0,
      dragging: false,
      cancelled: false,
    };
  });
  $("messages").addEventListener("pointermove", (event) => {
    if (!dragReply || dragReply.pointerId !== event.pointerId) return;
    if (dragReply.cancelled) return;
    const dx = event.clientX - dragReply.x;
    const dy = event.clientY - dragReply.y;
    if (!dragReply.dragging) {
      if (Math.abs(dy) > 14 && Math.abs(dy) > Math.abs(dx)) {
        dragReply.cancelled = true;
        return;
      }
      const towardCenter = dragReply.article.classList.contains("mine") ? dx < 0 : dx > 0;
      if (!towardCenter || Math.abs(dx) < 8 || Math.abs(dx) <= Math.abs(dy) * 1.2) return;
      dragReply.dragging = true;
      dragReply.article.classList.add("is-dragging");
      dragReply.article.setPointerCapture(event.pointerId);
    }
    dragReply.dx = dx;
    dragReply.article.style.transform = `translateX(${Math.max(-88, Math.min(88, dx * 0.65))}px)`;
    dragReply.article.classList.toggle("drag-reply-hint", Math.abs(dx) >= 48);
    event.preventDefault();
  });
  const finishReplyDrag = (event) => {
    if (!dragReply || (event && dragReply.pointerId !== event.pointerId)) return;
    const gesture = dragReply;
    dragReply = null;
    gesture.article.classList.remove("is-dragging", "drag-reply-hint");
    gesture.article.style.transform = "";
    if (gesture.dragging && Math.abs(gesture.dx) >= 56) selectReplyTarget(gesture.article);
  };
  $("messages").addEventListener("pointerup", finishReplyDrag);
  $("messages").addEventListener("pointercancel", finishReplyDrag);
  $("messages").addEventListener("lostpointercapture", finishReplyDrag);
  $("reply-cancel")?.addEventListener("click", clearReplyTarget);
  $("message").addEventListener("keydown", (event) => {
    if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      $("send-form").requestSubmit();
    }
  });
  $("message").addEventListener("input", () => {
    if (state === "chat" && Date.now() - typingAt > 2000) {
      typingAt = Date.now();
      api("typing", {
        chat_id: chatId,
        typing: $("message").value.length > 0,
      }).catch(() => {});
    }
  });
  $("emoji").addEventListener("click", () => {
    $("emojis").hidden = !$("emojis").hidden;
  });
  document.querySelectorAll(".emoji-choice").forEach((button) =>
    button.addEventListener("click", () => {
      if (state !== "chat") return;
      if (
        $("message").value.length + button.textContent.length <=
        cfg.maxLength
      )
        $("message").setRangeText(
          button.textContent,
          $("message").selectionStart,
          $("message").selectionEnd,
          "end",
        );
      $("message").focus();
      $("emojis").hidden = true;
    }),
  );
  $("report").addEventListener("click", () => $("report-dialog").showModal());
  $("report-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const result = await act("report", {
      ...Object.fromEntries(new FormData(event.currentTarget)),
      chat_id: lastChat,
    });
    if (result) {
      $("report-dialog").close();
      notice(word("reported"));
    }
  });
  document
    .querySelectorAll("[data-close]")
    .forEach((button) =>
      button.addEventListener("click", () => $(button.dataset.close).close()),
    );
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) {
      markRead();
      refresh();
    }
  });
  setInterval(() => {
    $("expand").hidden = !(
      state === "queue" &&
      queueAt &&
      Date.now() - queueAt > 45000
    );
  }, 1000);
  poll();
})();

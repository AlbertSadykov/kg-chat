'use strict';
(() => {
    const script = document.getElementById('kg-chat-presence');
    if (!script) return;
    const cfg = JSON.parse(script.dataset.config);
    const main = Boolean(document.getElementById('app'));
    const nativeFetch = window.fetch.bind(window);
    const rootURL = `${cfg.base}/`;
    const prefix = `kg-presence:${cfg.base}:`;
    const id = crypto.getRandomValues(new Uint32Array(2)).join('-');
    const ky = cfg.lang === 'ky';
    const words = cfg.words;
    const label = ky ? {
        chat:'Чат', open:'Толук чат', close:'Жашыруу', send:'Жөнөтүү', message:'Билдирүү…', waiting:'Сүйлөшүүчү изделүүдө…', ended:'Сүйлөшүү аяктады', notifications:'Билдирмелер', enabled:'Билдирмелер күйгүзүлдү', disabled:'Билдирмелер өчүрүлдү', found:'Сүйлөшүүчү табылды', incoming:'Жаңы билдирүү келди', return:'Чатка кайтыңыз', network:'Байланыш үзүлдү. Кайра туташууда…', enable:'Билдирмелерди күйгүзүү', disable:'Билдирмелерди өчүрүү', captcha:'Текшерүүнүн жообун жазыңыз', cancel:'Жокко чыгаруу'
    } : {
        chat:'Чат', open:'Открыть полный чат', close:'Свернуть', send:'Отправить', message:'Сообщение…', waiting:'Поиск собеседника…', ended:'Разговор завершён', notifications:'Уведомления', enabled:'Уведомления включены', disabled:'Уведомления выключены', found:'Собеседник найден', incoming:'Новое сообщение', return:'Вернитесь в чат, чтобы прочитать его', network:'Связь потеряна. Переподключение…', enable:'Включить уведомления', disable:'Выключить уведомления', captcha:'Введите ответ на проверку', cancel:'Отмена'
    };
    let localStore, tabStore;
    try { localStore = window.localStorage; } catch (_) { }
    try { tabStore = window.sessionStorage; } catch (_) { }
    const stored = (area, key, fallback = '') => { try { return area.getItem(prefix + key) ?? fallback; } catch (_) { return fallback; } };
    const save = (area, key, value) => { try { area.setItem(prefix + key, value); } catch (_) { } };
    const node = (tag, cls, text = '') => { const n=document.createElement(tag); n.className=cls; n.textContent=text; return n; };
    const button = (cls, text) => { const n=node('button',cls,text); n.type='button'; return n; };
    const bell = button('kg-notify-button secondary', '🔔');
    bell.hidden = true;
    (document.querySelector('.topnav') || document.body).append(bell);
    const toast = node('div', 'kg-presence-toast'); toast.hidden=true; toast.setAttribute('role','status'); document.body.append(toast);
    let toastTimer;
    const announce = (text, error=false) => {
        toast.textContent=text; toast.hidden=false; toast.classList.toggle('kg-error',error);
        clearTimeout(toastTimer); toastTimer=setTimeout(()=>{toast.hidden=true;},5000);
    };
    const widget=node('aside','kg-mini'); widget.hidden=true; widget.setAttribute('aria-label',label.chat);
    const launcher=button('kg-mini-launcher',label.chat); launcher.setAttribute('aria-controls','kg-mini-panel');
    const badge=node('span','kg-mini-badge'); badge.hidden=true; launcher.append(badge);
    const panel=node('section','kg-mini-panel'); panel.id='kg-mini-panel'; panel.hidden=true;
    const header=node('header','kg-mini-header');
    const heading=node('div','kg-mini-heading'); const title=node('strong','',label.chat); const peer=node('span','kg-mini-peer'); heading.append(title,peer);
    const minimize=button('kg-mini-minimize secondary','−'); minimize.setAttribute('aria-label',label.close); header.append(heading,minimize);
    const links=node('div','kg-mini-links'); const full=node('a','kg-mini-full',label.open+' ↗'); full.href=rootURL; links.append(full);
    const messages=node('div','kg-mini-messages'); messages.setAttribute('role','log'); messages.setAttribute('aria-live','polite'); messages.setAttribute('aria-label',label.chat);
    const status=node('p','kg-mini-status'); status.setAttribute('role','status');
    const typing=node('p','kg-mini-typing'); typing.setAttribute('aria-live','polite');
    const replyPreview=node('div','kg-mini-reply-preview'); replyPreview.hidden=true;
    const replyCopy=node('div','kg-mini-reply-copy');
    const replyTitle=node('strong',''); const replyText=node('span','');
    replyCopy.append(replyTitle,replyText);
    const replyCancel=button('kg-mini-reply-cancel','×');
    replyCancel.setAttribute('aria-label',words.cancel_reply||label.cancel);
    replyPreview.append(replyCopy,replyCancel);
    const form=node('form','kg-mini-composer'); const input=node('textarea',''); input.rows=1; input.maxLength=cfg.maxLength; input.placeholder=label.message; input.setAttribute('aria-label',label.message);
    const send=node('button','', '➤'); send.type='submit'; send.setAttribute('aria-label',label.send); form.append(input,send);
    panel.append(header,links,messages,status,typing,replyPreview,form); widget.append(panel,launcher); document.body.append(widget);
    let open=stored(tabStore,'mini-open')==='1', state='guest', client='', chat=0, cursor=0, read=0, requestedRead=0, cache=new Map();
    let initialized=false, lastData=null, lastBroadcast=0, lastMain=0, polling=false, timer=null, failures=0, typingAt=0, pending=null, replyTarget=null;
    let registration=null, subscribing=false, subscribeRetryAt=0, sending=false, subscriptionClient='', notifyOn=stored(localStore,'notifications')==='on', audio=null;
    let savedMeta;
    try { savedMeta=JSON.parse(stored(tabStore,'meta','{}')); } catch (_) { savedMeta={}; }
    let channel;
    try { channel=new BroadcastChannel(prefix+'events'); } catch (_) { }
    const broadcast=data=>{if(channel)channel.postMessage({...data,source:id});};
    const isReading=()=>!document.hidden && state==='chat' && (main || (open && messages.scrollHeight-messages.scrollTop-messages.clientHeight<90));
    const reflectOpen=()=>{
        panel.hidden=!open; launcher.setAttribute('aria-expanded',String(open));
        launcher.classList.toggle('kg-mini-is-open',open);
        if(open) { messages.scrollTop=messages.scrollHeight; markRead(); }
    };
    const setOpen=value=>{open=value; save(tabStore,'mini-open',value?'1':'0'); reflectOpen();};
    launcher.addEventListener('click',()=>setOpen(!open)); minimize.addEventListener('click',()=>setOpen(false));
    const updateBell=()=>{
        bell.title=notifyOn?label.disable:label.enable;
        bell.setAttribute('aria-label',bell.title); bell.setAttribute('aria-pressed',String(notifyOn));
        bell.classList.toggle('kg-notify-on',notifyOn);
    }; updateBell();
    const unlock=()=>{
        if(main) return;
        try { if(stored(localStore,'sounds','')==='off' || localStore?.getItem('kg-sounds')==='off') return; const Audio=window.AudioContext||window.webkitAudioContext; if(Audio&&!audio)audio=new Audio(); if(audio&&audio.state==='suspended')audio.resume().catch(()=>{}); } catch (_) { }
    };
    document.addEventListener('pointerdown',unlock,{passive:true}); document.addEventListener('keydown',unlock);
    const sound=incoming=>{
        try {
            if(main||!audio||audio.state!=='running'||localStore?.getItem('kg-sounds')==='off')return;
            const oscillator=audio.createOscillator(),gain=audio.createGain(),at=audio.currentTime;
            oscillator.frequency.setValueAtTime(incoming?740:480,at); oscillator.frequency.exponentialRampToValueAtTime(incoming?990:600,at+.08);
            gain.gain.setValueAtTime(.0001,at); gain.gain.exponentialRampToValueAtTime(.045,at+.012); gain.gain.exponentialRampToValueAtTime(.0001,at+.14);
            oscillator.connect(gain);gain.connect(audio.destination);oscillator.start(at);oscillator.stop(at+.15);oscillator.onended=()=>{oscillator.disconnect();gain.disconnect();};
        } catch (_) { }
    };
    const api=async(route,data=null,retry=true)=>{
        const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),20000);
        try {
            const response=await nativeFetch(`${cfg.base}/api/${route}`,{method:data===null?'GET':'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'X-CSRF-Token':cfg.csrf,...(data===null?{}:{'Content-Type':'application/json'})},body:data===null?undefined:JSON.stringify(data)});
            const result=await response.json();
            if(!response.ok||result.error){
                if(result.captcha&&data!==null&&retry){const answer=await challenge(result.captcha);if(answer)return api(route,{...data,...answer},false);}
                throw new Error(result.error||label.network);
            }
            return result;
        } catch(error) { if(error.name==='AbortError'||error instanceof TypeError)throw new Error(label.network);throw error; }
        finally {clearTimeout(timeout);}
    };
    const challenge=data=>new Promise(resolve=>{
        const dialog=node('dialog','kg-mini-captcha'),f=node('form',''); const h=node('h2','',label.captcha),q=node('label','',data.question),answer=node('input',''); answer.inputMode='numeric';answer.required=true;q.append(answer);
        const row=node('div','button-row'),ok=node('button','',label.send),cancel=button('secondary',label.cancel);ok.type='submit';row.append(ok,cancel);f.append(h,q,row);dialog.append(f);document.body.append(dialog);
        let finished=false;const done=value=>{if(finished)return;finished=true;dialog.close();dialog.remove();resolve(value);};
        cancel.addEventListener('click',()=>done(null));dialog.addEventListener('cancel',event=>{event.preventDefault();done(null);});f.addEventListener('submit',event=>{event.preventDefault();done({captcha_token:data.token,captcha_answer:answer.value});});dialog.showModal();answer.focus();
    });
    const worker=async()=>{
        if(registration)return registration;
        if(!window.isSecureContext||!('serviceWorker' in navigator))throw new Error('Для уведомлений нужен HTTPS и браузер с поддержкой Service Worker.');
        const scriptURL=new URL(`${cfg.base}/chat-sw.js`,location.origin).href;
        const previous=await navigator.serviceWorker.getRegistration(rootURL);
        if(previous&&previous.active&&previous.active.scriptURL!==scriptURL)throw new Error('На сайте уже используется другой Service Worker. Нужно объединить его с chat-sw.js.');
        const reg=await navigator.serviceWorker.register(scriptURL,{scope:rootURL});
        if(!reg.active){
            await new Promise((resolve,reject)=>{
                const current=reg.installing||reg.waiting;if(!current){reject(new Error('Service Worker не запущен.'));return;}
                const timeout=setTimeout(()=>reject(new Error('Service Worker не запустился. Проверьте /chat-sw.js.')),10000);
                const changed=()=>{if(current.state==='activated'){clearTimeout(timeout);current.removeEventListener('statechange',changed);resolve();}else if(current.state==='redundant'){clearTimeout(timeout);reject(new Error('Ошибка Service Worker.'));}};
                current.addEventListener('statechange',changed);changed();
            });
        }
        registration=reg;return reg;
    };
    const configureWorker=(reg,enabled)=>new Promise(resolve=>{
        if(!reg.active){resolve();return;}
        const channel=new MessageChannel(),timeout=setTimeout(()=>{channel.port1.close();resolve();},1500);
        channel.port1.onmessage=()=>{clearTimeout(timeout);channel.port1.close();resolve();};
        reg.active.postMessage({type:'kg-config',enabled,client,lang:cfg.lang,site:cfg.site},[channel.port2]);
    });
    const subscribe=async(manual=false)=>{
        if(!manual&&Date.now()<subscribeRetryAt)return;
        if(subscribing||!client||!notifyOn||!('Notification' in window)||Notification.permission!=='granted')return;
        if(subscriptionClient===client&&!manual)return;
        subscribing=true;
        try {
            const reg=await worker();await configureWorker(reg,true);const prepared=await api('push/prepare',{});
            const raw=atob(prepared.key.replace(/-/g,'+').replace(/_/g,'/'));const key=Uint8Array.from(raw,c=>c.charCodeAt(0));
            let sub=await reg.pushManager.getSubscription();
            if(sub&&sub.options.applicationServerKey){const old=Array.from(new Uint8Array(sub.options.applicationServerKey));if(old.length!==key.length||old.some((v,i)=>v!==key[i])){await sub.unsubscribe();sub=null;}}
            if(!sub)sub=await reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key});
            await api('push/subscribe',sub.toJSON());subscriptionClient=client;subscribeRetryAt=0;
            if(manual)announce(label.enabled);
        } catch(error){subscribeRetryAt=Date.now()+60000;if(manual)announce(error.message,true);}
        finally {subscribing=false;}
    };
    bell.addEventListener('click',async()=>{
        if(!('Notification' in window)||!window.isSecureContext){announce('Для уведомлений нужен HTTPS и поддержка уведомлений браузером.',true);return;}
        if(notifyOn){
            notifyOn=false;save(localStore,'notifications','off');updateBell();
            try {const reg=await worker();await configureWorker(reg,false);const sub=await reg.pushManager.getSubscription();if(sub){try{await api('push/unsubscribe',{endpoint:sub.endpoint});}finally{await sub.unsubscribe();}}}catch(_){}
            subscriptionClient='';announce(label.disabled);return;
        }
        if(Notification.permission==='denied'){announce('Разрешите уведомления в настройках сайта вашего браузера.',true);return;}
        // Запрос разрешения выполняется непосредственно после нажатия пользователя.
        const permission=Notification.permission==='granted'?'granted':await Notification.requestPermission();
        if(permission!=='granted')return;
        notifyOn=true;save(localStore,'notifications','on');updateBell();await subscribe(true);
    });
    const notify=async(type,messageId=0)=>{
        if(!notifyOn||!client||!('Notification' in window)||Notification.permission!=='granted'||isReading())return;
        const data={type,client,chat,id:messageId,time:Math.floor(Date.now()/1000),lang:cfg.lang,site:cfg.site};
        try {const reg=await worker();await configureWorker(reg,true);const target=reg.active;if(target)target.postMessage({type:'kg-notify',event:data});}
        catch(_){
            // Запасной вариант для настольных браузеров без работающего worker.
            try {const n=new Notification(type==='found'?label.found:label.incoming,{body:label.return,tag:`kgchat-${client}-${chat}`,icon:`${cfg.base}/assets/logo.svg`});n.onclick=()=>{window.focus();location.assign(rootURL);n.close();};}catch(_){}
        }
    };
    const persistMeta=()=>save(tabStore,'meta',JSON.stringify({client,chat,cursor}));
    const markRead=async()=>{
        if(!isReading()||cursor<=Math.max(read,requestedRead))return;
        const at=cursor,cid=chat;requestedRead=at;
        try {
            const result=await api('read',{chat_id:cid,id:at});
            if(result.ok&&chat===cid){read=Math.max(read,at);renderBadge();broadcast({type:'read',client,chat,id:at});closeNotices();}
        }catch(_){if(chat===cid)requestedRead=read;}
    };
    const closeNotices=()=>{if(registration&&registration.active&&client)registration.active.postMessage({type:'kg-clear',client,chat});};
    const renderBadge=()=>{
        let count=0;for(const item of cache.values())if(!item.mine&&item.id>read)count++;
        badge.hidden=!count;badge.textContent=count>99?'99+':String(count);launcher.setAttribute('aria-label',`${label.chat}${count?': '+count:''}`);
    };
    const clearReply=()=>{
        replyTarget=null;replyPreview.hidden=true;replyTitle.textContent='';replyText.textContent='';
    };
    const selectReply=article=>{
        const item=cache.get(Number(article.dataset.id));
        if(!item)return;
        replyTarget={id:Number(item.id),body:item.body,mine:Boolean(item.mine)};
        replyTitle.textContent=words[item.mine?'reply_to_you':'reply_to_peer']||label.chat;
        replyText.textContent=item.body;
        replyPreview.hidden=false;
        input.focus();
    };
    const toggleLike=async article=>{
        const messageId=Number(article.dataset.id);
        if(state!=='chat'||!messageId||article.dataset.likePending==='1')return;
        article.dataset.likePending='1';
        try {
            const result=await api('like',{chat_id:chat,message_id:messageId});
            const item=cache.get(messageId);
            if(item){item.like_count=result.like_count;item.liked_by_me=Boolean(result.liked);}
            renderMessages(lastData||{});
            clearTimeout(timer);poll(true);
        } catch(error) { status.textContent=error.message; }
        finally { delete article.dataset.likePending; }
    };
    const renderMessages=data=>{
        const nearBottom=messages.scrollHeight-messages.scrollTop-messages.clientHeight<90;
        messages.replaceChildren();
        for(const item of Array.from(cache.values()).sort((a,b)=>a.id-b.id)){
            const article=node('article',`kg-mini-bubble${item.mine?' kg-mine':''}`),p=node('p','',item.body),meta=node('small','');
            article.dataset.id=String(item.id);
            if(item.reply&&typeof item.reply.body==='string'){
                const quote=node('div','kg-mini-reply-quote');
                quote.append(node('strong','',words[item.reply.mine?'reply_to_you':'reply_to_peer']||label.chat),node('span','',item.reply.body));
                article.append(quote);
            }
            const date=new Date(item.created_at.replace(' ','T')+'Z');meta.textContent=date.toLocaleTimeString(ky?'ky-KG':'ru-RU',{hour:'2-digit',minute:'2-digit'});
            if(item.mine)meta.textContent+=' · '+(item.id<=data.peer_read?(words.read||'Прочитано'):item.id<=data.peer_delivered?(words.delivered||'Доставлено'):(words.sent||'Отправлено'));
            article.append(p,meta);
            const reaction=node('span','kg-mini-reaction','❤️');reaction.setAttribute('role','img');reaction.setAttribute('aria-label',words.liked||'Liked');reaction.hidden=!(Number(item.like_count)>0);
            article.append(reaction);messages.append(article);
        }
        if(nearBottom||!initialized)messages.scrollTop=messages.scrollHeight;
    };
    const observe=(data,shared=false)=>{
        if(!data||typeof data.state!=='string')return;
        const previous=state,oldChat=chat,oldClient=client,first=!initialized;
        state=data.state;client=data.client_key||client;
        if(data.client_key&&oldClient&&oldClient!==data.client_key){cache.clear();cursor=0;read=0;chat=0;pending=null;input.value='';subscriptionClient='';}
        if(['guest','expired'].includes(state)){
            widget.hidden=true;bell.hidden=true;cache.clear();messages.replaceChildren();chat=0;cursor=0;read=0;client='';pending=null;input.value='';clearReply();
            if(oldClient){notifyOn=false;save(localStore,'notifications','off');updateBell();if(registration&&registration.active)registration.active.postMessage({type:'kg-clear',client:oldClient});}
            initialized=true;return;
        }
        bell.hidden=['banned','maintenance'].includes(state);widget.hidden=main||!['chat','queue'].includes(state);
        if(state==='chat'){
            const changed=chat!==data.chat_id;chat=Number(data.chat_id);
            if(changed){cache.clear();cursor=0;read=0;requestedRead=0;pending=null;input.value='';clearReply();}
            read=Math.max(read,Number(data.my_read||0));
            let newIncoming=0;
            for(const item of data.messages||[]){
                if(cache.has(item.id))continue;
                cache.set(item.id,item);cursor=Math.max(cursor,item.id);
                const duringNavigation=first&&savedMeta.client===client&&Number(savedMeta.chat)===chat&&item.id>Number(savedMeta.cursor||0);
                if(!item.mine&&item.id>read&&((!first&&!changed)||duringNavigation))newIncoming=Math.max(newIncoming,item.id);
            }
            for(const update of data.like_updates||[]){
                const item=cache.get(Number(update.id));
                if(item){item.like_count=Number(update.like_count)||0;item.liked_by_me=Boolean(update.liked_by_me);}
            }
            while(cache.size>300)cache.delete(cache.keys().next().value);
            title.textContent=data.peer?.nickname||label.chat;
            peer.textContent=data.peer?`${words[data.peer.gender]||data.peer.gender} · ${data.peer.age} · ${data.peer.city}`:'';
            status.textContent='';typing.textContent=data.typing?(words.typing||'Собеседник печатает…'):'';
            input.disabled=false;send.disabled=sending;
            if(!main)renderMessages(data);
            if(newIncoming){if(!main&&(!shared||!document.hidden))sound(true);notify('message',newIncoming);}
            if(!first&&changed&&['queue','idle'].includes(previous)){if(!main)announce(label.found+' 👋');notify('found');}
            if(!main&&changed)messages.scrollTop=messages.scrollHeight;
        } else {
            chat=0;cursor=0;cache.clear();messages.replaceChildren();input.disabled=true;send.disabled=true;typing.textContent='';clearReply();
            title.textContent=label.chat;peer.textContent='';status.textContent=state==='queue'?label.waiting:label.ended;
            if(previous==='chat'&&!main)announce(data.reason||label.ended);
        }
        form.hidden=state!=='chat';
        lastData=data;initialized=true;persistMeta();renderBadge();
        if(!main)markRead();else if(isReading())closeNotices();
        subscribe().catch(()=>{});
    };
    // Основной chat.js продолжает работать; модуль наблюдает за его ответами API.
    window.fetch=(input,options)=>{
        let path='';try{path=new URL(typeof input==='string'?input:input.url,location.href).pathname;}catch(_){}
        const stateRequest=path===`${cfg.base}/api/state`;
        if(main&&stateRequest&&document.hidden&&channel&&lastData&&Date.now()-lastBroadcast<7000){
            const url=new URL(typeof input==='string'?input:input.url,location.href),requested=Number(url.searchParams.get('cursor')||0);
            const data={...lastData,messages:Array.from(cache.values()).filter(item=>item.id>requested).sort((a,b)=>a.id-b.id).slice(0,100)};
            return Promise.resolve(new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}}));
        }
        return nativeFetch(input,options).then(response=>{
            if(response.ok&&stateRequest)response.clone().json().then(data=>{observe(data);broadcast({type:'state',main,data});}).catch(()=>{});
            if(response.ok&&path===`${cfg.base}/api/read`&&options?.body){
                try {const request=JSON.parse(options.body);response.clone().json().then(result=>{if(result.ok&&Number(request.chat_id)===chat){read=Math.max(read,Number(request.id));renderBadge();broadcast({type:'read',client,chat,id:request.id});}}).catch(()=>{});}catch(_){}
            }
            return response;
        });
    };
    const lease=()=>{try{return JSON.parse(stored(localStore,'lease','{}'));}catch(_){return {};}};
    const renew=()=>save(localStore,'lease',JSON.stringify({owner:id,main,time:Date.now()}));
    const allowedToPoll=()=>{
        if(main)return false;
        const current=lease();
        if(channel&&(Date.now()-lastMain<7000||current.owner!==id&&current.main&&Date.now()-current.time<7000))return false;
        if(current.owner&&current.owner!==id&&Date.now()-current.time<7000)return false;
        renew();return true;
    };
    const poll=async(force=false)=>{
        if(polling||main)return;
        if(!force&&!allowedToPoll()){timer=setTimeout(()=>poll(),cfg.poll*1000);return;}
        if(!force&&['guest','expired'].includes(state)&&initialized)return;
        polling=true;
        try {const ids=Array.from(cache.keys()).slice(-300).join(',');const data=await api(`state?cursor=${cursor}&transport=poll&message_ids=${ids}`);failures=0;observe(data);broadcast({type:'state',main:false,data});}
        catch(error){failures++;if(state==='chat'||state==='queue')status.textContent=error.message;}
        finally {polling=false;clearTimeout(timer);if(!['guest','expired'].includes(state)||!initialized)timer=setTimeout(()=>poll(),Math.min(15000,(state==='idle'?15000:cfg.poll*1000)*Math.max(1,failures)));}
    };
    if(channel)channel.onmessage=event=>{
        const data=event.data;if(!data||data.source===id)return;
        if(data.type==='main-alive'){lastMain=Date.now();return;}
        if(data.type==='state'){lastBroadcast=Date.now();if(data.main)lastMain=Date.now();observe(data.data,true);}
        else if(data.type==='read'&&data.client===client&&Number(data.chat)===chat){read=Math.max(read,Number(data.id));renderBadge();}
    };
    let lastTouchLike=null,lastTouchLikeAt=0,replyDrag=null;
    messages.addEventListener('dblclick',event=>{
        if(Date.now()-lastTouchLikeAt<500)return;
        const article=event.target instanceof Element?event.target.closest('.kg-mini-bubble[data-id]'):null;
        if(article){event.preventDefault();toggleLike(article);}
    });
    messages.addEventListener('pointerdown',event=>{
        const article=event.target instanceof Element?event.target.closest('.kg-mini-bubble[data-id]'):null;
        if(event.pointerType==='mouse'&&(event.button!==0||event.target.closest('button,a,input,textarea,select')))return;
        if(!article||state!=='chat')return;
        const now=Date.now();
        if(event.pointerType==='touch'&&lastTouchLike?.article===article&&now-lastTouchLike.at<400&&Math.hypot(event.clientX-lastTouchLike.x,event.clientY-lastTouchLike.y)<32){
            event.preventDefault();lastTouchLikeAt=now;lastTouchLike=null;toggleLike(article);return;
        }
        if(event.pointerType==='touch')lastTouchLike={article,at:now,x:event.clientX,y:event.clientY};
        replyDrag={article,pointerId:event.pointerId,x:event.clientX,y:event.clientY,dx:0,dragging:false,cancelled:false};
    });
    messages.addEventListener('pointermove',event=>{
        if(!replyDrag||replyDrag.pointerId!==event.pointerId||replyDrag.cancelled)return;
        const dx=event.clientX-replyDrag.x,dy=event.clientY-replyDrag.y;
        if(!replyDrag.dragging){
            if(Math.abs(dy)>14&&Math.abs(dy)>Math.abs(dx)){replyDrag.cancelled=true;return;}
            const towardCenter=replyDrag.article.classList.contains('kg-mine')?dx<0:dx>0;
            if(!towardCenter||Math.abs(dx)<8||Math.abs(dx)<=Math.abs(dy)*1.2)return;
            replyDrag.dragging=true;replyDrag.article.classList.add('kg-mini-dragging');replyDrag.article.setPointerCapture(event.pointerId);
        }
        replyDrag.dx=dx;replyDrag.article.style.transform=`translateX(${Math.max(-72,Math.min(72,dx*.65))}px)`;
        replyDrag.article.classList.toggle('kg-mini-drag-hint',Math.abs(dx)>=42);event.preventDefault();
    });
    const finishReplyDrag=event=>{
        if(!replyDrag||(event&&replyDrag.pointerId!==event.pointerId))return;
        const gesture=replyDrag;replyDrag=null;gesture.article.classList.remove('kg-mini-dragging','kg-mini-drag-hint');gesture.article.style.transform='';
        if(gesture.dragging&&Math.abs(gesture.dx)>=52)selectReply(gesture.article);
    };
    messages.addEventListener('pointerup',finishReplyDrag);
    messages.addEventListener('pointercancel',finishReplyDrag);
    messages.addEventListener('lostpointercapture',finishReplyDrag);
    replyCancel.addEventListener('click',clearReply);
    form.addEventListener('submit',async event=>{
        event.preventDefault();const body=input.value.trim();if(!body||state!=='chat'||sending)return;
        if(!pending||pending.body!==body||pending.chat_id!==chat||pending.reply_to_id!==(replyTarget?.id||0)){const bytes=crypto.getRandomValues(new Uint8Array(16));pending={chat_id:chat,body,reply_to_id:replyTarget?.id||0,nonce:Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')};}
        const submission=pending;sending=true;send.disabled=true;
        try {const result=await api('send',submission);if(!result.ok||!result.id)throw new Error(label.ended);if(chat===submission.chat_id){input.value='';pending=null;clearReply();sound(false);}clearTimeout(timer);poll(true);}
        catch(error){status.textContent=error.message;}
        finally {sending=false;send.disabled=state!=='chat';}
    });
    input.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){event.preventDefault();form.requestSubmit();}});
    input.addEventListener('input',()=>{if(state==='chat'&&Date.now()-typingAt>2000){typingAt=Date.now();api('typing',{chat_id:chat,typing:input.value.length>0}).catch(()=>{});}});
    messages.addEventListener('scroll',()=>markRead(),{passive:true});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden){if(main)renew();else{clearTimeout(timer);poll();}markRead();closeNotices();}});
    window.addEventListener('pagehide',()=>{persistMeta();const current=lease();if(current.owner===id)save(localStore,'lease','{}');});
    window.addEventListener('storage',event=>{if(event.key===prefix+'notifications'){notifyOn=event.newValue==='on';updateBell();if(notifyOn)subscribe();}});
    if('serviceWorker' in navigator)navigator.serviceWorker.addEventListener('message',event=>{
        if(event.data?.type==='kg-view')event.ports[0]?.postMessage({client,chat,reading:isReading(),lang:cfg.lang,site:cfg.site});
        else if(event.data?.type==='kg-refresh'&&!main){clearTimeout(timer);poll(true);}
    });
    setInterval(()=>{if(main&&!document.hidden){renew();broadcast({type:'main-alive'});}else if(!main&&lease().owner===id&&!document.hidden)renew();},2000);
    reflectOpen();
    if(main&&!document.hidden)renew();
    else if(!main&&cfg.possibleSession)poll(true);
    // Возврат из back-forward cache должен восстановить опрос.
    window.addEventListener('pageshow',event=>{if(event.persisted&&!main&&cfg.possibleSession){clearTimeout(timer);poll(true);}});
})();

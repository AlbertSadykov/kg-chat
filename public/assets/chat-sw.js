'use strict';
// Worker не кеширует страницы, CSRF-токены и переписку.
const fallback=new Map();
const root=new URL('./',self.location.href);
const database=()=>new Promise((resolve,reject)=>{
    const request=indexedDB.open('kgchat-notification-meta',1);
    request.onupgradeneeded=()=>{request.result.createObjectStore('meta');};
    request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error);
});
const get=async key=>{
    try {const db=await database();return await new Promise((resolve,reject)=>{const tx=db.transaction('meta','readonly');const request=tx.objectStore('meta').get(key);request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error);tx.oncomplete=()=>db.close();});}
    catch(_){return fallback.get(key);}
};
const put=async(key,value)=>{
    fallback.set(key,value);
    try {const db=await database();await new Promise((resolve,reject)=>{const tx=db.transaction('meta','readwrite');tx.objectStore('meta').put(value,key);tx.oncomplete=()=>{db.close();resolve();};tx.onerror=()=>{db.close();reject(tx.error);};});}catch(_){}
};
const claim=async event=>{
    const key=`event:${event.client}:${event.chat}:${event.type}`;
    const current=event.type==='found'?1:Number(event.id);
    try {
        const db=await database();
        return await new Promise((resolve,reject)=>{
            let accepted=false;const tx=db.transaction('meta','readwrite'),store=tx.objectStore('meta'),request=store.get(key);
            request.onsuccess=()=>{const previous=request.result;if(!previous||current>previous.id){accepted=true;store.put({id:current,time:Date.now()},key);}};
            // В хранилище только номера событий; старые метаданные удаляются.
            const cursor=store.openCursor();cursor.onsuccess=()=>{const item=cursor.result;if(!item)return;if(String(item.key).startsWith('event:')&&item.value.time<Date.now()-86400000)item.delete();item.continue();};
            tx.oncomplete=()=>{db.close();resolve(accepted);};tx.onerror=()=>{db.close();reject(tx.error);};
        });
    } catch(_) {if((fallback.get(key)||0)>=current)return false;fallback.set(key,current);return true;}
};
const view=client=>new Promise(resolve=>{
    const channel=new MessageChannel(),timeout=setTimeout(()=>{channel.port1.close();resolve(null);},300);
    channel.port1.onmessage=event=>{clearTimeout(timeout);channel.port1.close();resolve(event.data);};
    client.postMessage({type:'kg-view'},[channel.port2]);
});
const clear=async(client,chat)=>{
    const notifications=await self.registration.getNotifications();
    notifications.forEach(n=>{if(n.data?.client===client&&(!chat||Number(n.data.chat)===Number(chat)))n.close();});
};
const deliver=async(event,fromPush=false)=>{
    if(!event||!['message','found'].includes(event.type)||!/^[a-f0-9]{64}$/.test(event.client||'')||!Number.isSafeInteger(Number(event.chat))||Number(event.chat)<1)return;
    if(event.type==='message'&&(!Number.isSafeInteger(Number(event.id))||Number(event.id)<1))return;
    const cfg=await get('config');
    if(!cfg?.enabled||cfg.client!==event.client)return;
    const windows=await self.clients.matchAll({type:'window',includeUncontrolled:true});
    const views=await Promise.all(windows.map(view));
    const reading=views.some(v=>v?.client===event.client&&Number(v.chat)===Number(event.chat)&&v.reading);
    const fresh=await claim(event);
    if(!fresh&&!fromPush)return;
    windows.forEach(client=>client.postMessage({type:'kg-refresh'}));
    // Настоящий Push обязан показывать уведомление; при чтении — без звука.
    if(reading&&!fromPush){await clear(event.client,event.chat);return;}
    const ky=cfg.lang==='ky';
    await self.registration.showNotification(event.type==='found'?(ky?'Сүйлөшүүчү табылды':'Собеседник найден'):(cfg.site||'Кезик'),{
        body:event.type==='found'?(ky?'Чатка кайтыңыз 👋':'Вернитесь в чат 👋'):(ky?'Жаңы билдирүү келди. Чатка кайтыңыз.':'Вам новое сообщение. Вернитесь в чат.'),
        icon:new URL('assets/logo.svg',root).href,
        tag:`kgchat-${event.client}-${event.chat}`,
        renotify:false,
        silent:reading||!fresh,
        data:{client:event.client,chat:Number(event.chat),url:root.href}
    });
};
self.addEventListener('install',()=>self.skipWaiting());
self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));
self.addEventListener('message',event=>{
    if(event.data?.type==='kg-config')event.waitUntil(put('config',{enabled:Boolean(event.data.enabled),client:String(event.data.client||''),lang:event.data.lang==='ky'?'ky':'ru',site:String(event.data.site||'Кезик').slice(0,100)}).then(()=>event.ports[0]?.postMessage({ok:true})));
    else if(event.data?.type==='kg-notify')event.waitUntil(deliver(event.data.event));
    else if(event.data?.type==='kg-clear')event.waitUntil(clear(event.data.client,event.data.chat));
});
self.addEventListener('push',event=>{
    let data;try{data=event.data.json();}catch(_){return;}
    event.waitUntil(deliver(data,true));
});
self.addEventListener('notificationclick',event=>{
    event.notification.close();
    event.waitUntil((async()=>{
        const windows=await self.clients.matchAll({type:'window',includeUncontrolled:true});
        const full=windows.find(client=>{const url=new URL(client.url);return url.origin===root.origin&&url.pathname.replace(/\/$/,'')===root.pathname.replace(/\/$/,'');});
        if(full){await full.focus();full.postMessage({type:'kg-refresh'});return;}
        await self.clients.openWindow(root.href);
    })());
});

/* Topic learning uses deliberate actions and visible detail reading, never card impressions. */
(() => {
  'use strict';
  globalThis.ASCLAInterestLearning = ({root, api}) => {
    let stop=()=>{};

    async function watch(post, dialog, administrative=false) {
      stop();
      if (!dialog || administrative || post.status!=='publish' || post.meta?.chatham || post.meta?.micro) return;
      let cancelled=false, interval, observer;
      const finish=()=>{cancelled=true;clearInterval(interval);observer?.disconnect();};
      stop=finish;
      observer=new MutationObserver(()=>{if(!dialog.isConnected) finish();});
      observer.observe(root,{childList:true});
      try {
        const start=await api(`items/${Number(post.id)}/reading`,{});
        if(cancelled || !dialog.isConnected || !start.ticket) { finish();return; }
        let active=0, previous=performance.now();
        interval=setInterval(()=>{
          const now=performance.now(), delta=Math.min(1200,now-previous);previous=now;
          if(!document.hidden && document.hasFocus() && dialog.isConnected) active+=delta;
          if(active<start.seconds*1000) return;
          finish();
          void api(`items/${Number(post.id)}/read`,{ticket:start.ticket}).catch(()=>{});
        },500);
      } catch { finish(); }
    }

    return {watch};
  };
})();

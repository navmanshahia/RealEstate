(function CalendarUI(){
  'use strict';
  const previous=workContent;
  if(new URLSearchParams(location.search).get('calendar')==='connected')state.section='calendar';
  workContent=function(){
    previous();
    if(state.section!=='calendar')return;
    const container=document.createElement('div');
    container.className='panel spacer';
    container.id='calendar-connect-panel';
    container.innerHTML='<h3>Google & Outlook calendar synchronization</h3><p>Connect a provider to create and update your appointments automatically. You can also export individual .ics files.</p><div id="calendar-providers">Checking connections…</div>';
    $('#workcontent .workhead')?.after(container);
    loadProviders();
  };
  async function loadProviders(){
    const el=$('#calendar-providers');if(!el||state.demo){if(el)el.textContent='Unavailable in demo mode.';return;}
    try{
      const info=await api('calendar_status');const names={google:'Google Calendar',microsoft:'Outlook / Microsoft 365'};
      if(!$('#calendar-providers'))return;
      $('#calendar-providers').innerHTML=Object.entries(names).map(([k,label])=>{
        const connected=info.connected.includes(k),available=info.available[k];
        return `<div class="listrow"><div><strong>${label}</strong><p class="helper">${connected?'Connected · appointments sync when saved':available?'Ready to connect':'Enter OAuth credentials in app/config.php to enable'}</p></div>${connected?`<button class="outline small" data-calendar="disconnect" data-provider="${k}">Disconnect</button>`:`<button class="outline small" data-calendar="connect" data-provider="${k}" ${available?'':'disabled'}>Connect</button>`}</div>`;
      }).join('')+'<p class="helper">Connection is per agent workspace. Event start times use the timezone in your Website & settings. Appointments default to one hour; cancellations remove linked events. Provider permissions must be granted during OAuth setup.</p>';
    }catch(err){if($('#calendar-providers'))$('#calendar-providers').textContent=err.message;}
  }
  document.addEventListener('click',async e=>{
    const b=e.target.closest('button[data-calendar]');if(!b)return;
    e.preventDefault();e.stopImmediatePropagation();
    if(b.dataset.calendar==='connect')location.href='api.php?action=calendar_start&provider='+encodeURIComponent(b.dataset.provider);
    if(b.dataset.calendar==='disconnect'){
      if(!confirm('Disconnect this calendar? Previously synced events will remain in your calendar.'))return;
      try{await api('calendar_disconnect',{provider:b.dataset.provider});await loadProviders();toast('Calendar disconnected');}catch(err){toast(err.message);}
    }
  },true);
})();

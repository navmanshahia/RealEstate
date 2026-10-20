(function EstateMailbox(){
  'use strict';
  const previous=workContent;
  if(new URLSearchParams(location.search).get('mailbox')==='connected')state.section='inbox';
  let connections=[];
  workContent=function(){
    previous();
    if(state.section!=='inbox')return;
    const container=document.createElement('div');
    container.className='panel spacer';
    container.id='connected-mailbox-panel';
    container.innerHTML='<h3>Connected Gmail / Outlook inbox</h3><p class="helper">Sync recent incoming mail from known CRM contacts, or send through your connected mailbox. A provider connection is required; no emails are read before you authorize it.</p><div id="mailbox-connections">Checking connections…</div>';
    $('#workcontent .workhead')?.after(container);
    loadMailbox();
  };
  async function loadMailbox(){
    const container=$('#mailbox-connections');
    if(!container)return;
    if(state.demo){container.textContent='Mailboxes cannot be connected in demo mode.';return;}
    try{
      const info=await api('mailbox_status');
      connections=info.connected;
      if(!$('#mailbox-connections'))return;
      const names={google:'Gmail',microsoft:'Outlook / Microsoft 365'};
      container.innerHTML=Object.entries(names).map(([provider,name])=>{
        const connected=connections.includes(provider),available=info.available[provider];
        const button=connected?'<button class="outline small" data-mailbox="sync" data-provider="'+provider+'">Sync latest mail</button> <button class="outline small" data-mailbox="compose" data-provider="'+provider+'">Send email</button> <button class="ghost small" data-mailbox="disconnect" data-provider="'+provider+'">Disconnect</button>':available?'<button class="outline small" data-mailbox="connect" data-provider="'+provider+'">Connect '+name+'</button>':'<span class="tag">OAuth credentials required</span>';
        return '<div class="listrow"><div><strong>'+name+'</strong><p class="helper">'+(connected?'Connected to this agent workspace':available?'Ready to connect':'Configure your app credentials on the server')+'</p></div><div class="actions">'+button+'</div></div>';
      }).join('')+'<p class="helper">Only messages from existing CRM contact addresses are imported. Gmail/Outlook tokens are encrypted and kept private on the server. Connecting requires separate mail scopes and provider approval. Sync is manual; automatic background mailbox polling is not enabled.</p>';
    }catch(err){if($('#mailbox-connections'))$('#mailbox-connections').textContent=err.message;}
  }
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-mailbox]');
    if(!button)return;
    event.preventDefault();event.stopImmediatePropagation();
    const provider=button.dataset.provider,action=button.dataset.mailbox;
    try{
      if(action==='connect'){location.href='api.php?action=mailbox_start&provider='+encodeURIComponent(provider);return;}
      if(action==='disconnect'){
        if(!confirm('Disconnect this mailbox? The existing CRM activity history will remain.'))return;
        await api('mailbox_disconnect',{provider});await loadMailbox();toast('Mailbox disconnected');return;
      }
      if(action==='sync'){
        button.disabled=true;
        const outcome=await api('mailbox_sync',{provider});
        state.records=await api('records');workContent();
        toast('Inbox checked: '+outcome.checked+' messages; '+outcome.imported+' new contact conversations');return;
      }
      if(action==='compose'){
        const contacts=byKind('contact').filter(row=>row.data.email);
        if(!contacts.length){toast('Create a client contact with an email address first.');return;}
        const options=contacts.map(row=>'<option value="'+esc(row.id)+'">'+esc(row.data.name)+' — '+esc(row.data.email)+'</option>').join('');
        modal('Send from '+(provider==='google'?'Gmail':'Outlook'),'<form id="mailbox-send-form" data-provider="'+provider+'"><label>CRM contact<select name="contact" required>'+options+'</select></label><label>Subject<input name="subject" maxlength="180" required></label><label>Message<textarea name="message" required maxlength="8000"></textarea></label><p class="error formmessage"></p><button type="submit">Send email</button></form>');
      }
    }catch(err){toast(err.message);}finally{if(action==='sync')button.disabled=false;}
  },true);
  document.addEventListener('submit',async event=>{
    const form=event.target;
    if(form.id!=='mailbox-send-form')return;
    event.preventDefault();event.stopImmediatePropagation();
    const button=form.querySelector('button[type=submit]'),error=form.querySelector('.formmessage');
    button.disabled=true;if(error)error.textContent='';
    try{
      const details=Object.fromEntries(new FormData(form));
      await api('mailbox_send',{...details,provider:form.dataset.provider});
      state.records=await api('records');$('#modal').close();workContent();toast('Email submitted to connected mailbox');
    }catch(err){if(error)error.textContent=err.message;else toast(err.message);}
    finally{button.disabled=false;}
  },true);
})();
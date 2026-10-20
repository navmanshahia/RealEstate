(function EstateCustomerSMS(){
 'use strict';
 if(!fields.contact.some(f=>f[0]==='sms_consent')){
   fields.contact.push(['sms_consent','Client explicitly agreed to receive SMS','checkbox']);
   fields.contact.push(['sms_consent_source','SMS permission source (web form, in person, date, etc.)']);
 }
 const previousEnquire=enquire;
 enquire=function(workspace,property){
   previousEnquire(workspace,property);
   const form=$('#enquiryform');
   if(!form)return;
   const label=document.createElement('label');
   label.className='wide checkboxlabel';
   label.innerHTML='<input type="checkbox" name="sms_consent" value="1"> I agree to receive text messages from this real estate agent about my enquiry. Message and data rates may apply. Reply STOP to opt out.';
   const btn=form.querySelector('button:not([type=button])');
   if(btn)btn.before(label);else form.append(label);
 };
 const originalWork=workContent;
 workContent=function(){
   originalWork();
   if(!['inbox','contacts','leads'].includes(state.section))return;
   const actions=$('#workcontent .workhead .actions');
   if(!actions)return;
   const btn=document.createElement('button');
   btn.className='outline';btn.type='button';btn.dataset.sms='compose';btn.textContent='Text a client';actions.prepend(btn);
   if(state.section==='inbox'){
     const p=document.createElement('p');p.className='helper';
     p.textContent='Text clients from the CRM and view replies. Twilio and recorded SMS permission are required. STOP immediately blocks further texts.';
     $('#workcontent .workhead')?.after(p);
   }
 };
 const originalEditor=editor;
 editor=function(kind,id,prefill){
   originalEditor(kind,id,prefill);
   const record=state.records.find(r=>r.id===id);
   if(!record||record.kind!=='contact')return;
   const foot=$('#recordform .modalfoot');
   if(!foot)return;
   const btn=document.createElement('button');
   btn.type='button';btn.className='outline small';btn.dataset.sms='compose';btn.dataset.contact=id;btn.textContent='Text this client';
   foot.prepend(btn);
   const p=document.createElement('p');
   p.className='helper';
   p.textContent='Record real SMS consent and how it was obtained. New CRM contacts are not opted in automatically.';
   foot.before(p);
 };
 const clients=()=>byKind('contact').filter(r=>r.data.phone);
 let selected='';
 function consentText(contact){
   if(!contact)return 'Choose a client.';
   if(contact.data.sms_opt_out)return 'Customer opted out of SMS. Do not text.';
   if(!contact.data.sms_consent||!contact.data.sms_consent_source)return 'SMS permission is not recorded. Edit the contact to record the source of consent first.';
   return 'Permission source: '+contact.data.sms_consent_source;
 }
 async function compose(id=''){
   if(state.demo){toast('SMS is unavailable in demo mode.');return;}
   const list=clients();if(!list.length){toast('Add a CRM client with a mobile number first.');return;}
   const info=await api('sms_status');
   selected=list.some(r=>r.id===id)?id:list[0].id;
   const options=list.map(r=>'<option value="'+esc(r.id)+'" '+(r.id===selected?'selected':'')+'>'+esc(r.data.name)+' — '+esc(r.data.phone)+'</option>').join('');
   const notice=info.configured?'Twilio sender: '+esc(info.sender):'Twilio needs configuring before real SMS can be sent.';
   modal('Text a client','<p class="helper">'+notice+'</p><form id="customer-sms-form"><label>Client<select name="contact" id="sms-contact">'+options+'</select></label><p id="sms-permission" class="helper"></p><label>Text message<textarea name="message" maxlength="600" rows="4" placeholder="Hi! Just following up about the viewing…" required></textarea></label><p class="helper">Agent name, contact email and STOP instructions are included automatically. Carrier message and data rates may apply.</p><p class="error formmessage"></p><button type="submit" '+(info.configured?'':'disabled')+'>Send SMS</button></form><div class="panel spacer"><h3>SMS conversation</h3><div id="customer-sms-thread">Loading messages…</div></div>');
   await thread(selected);
   if(!info.configured&&$('#customer-sms-form button'))$('#customer-sms-form button').disabled=true;
 }
 async function thread(id){
   selected=id;
   const contact=byKind('contact').find(r=>r.id===id);
   if($('#sms-permission'))$('#sms-permission').textContent=consentText(contact);
   if($('#customer-sms-form button[type=submit]'))$('#customer-sms-form button[type=submit]').disabled=!(contact&&contact.data.sms_consent&&contact.data.sms_consent_source&&!contact.data.sms_opt_out);
   try {
     const list=await api('sms_history&contact='+encodeURIComponent(id));
     if(!$('#customer-sms-thread')||id!==selected)return;
     $('#customer-sms-thread').innerHTML=list.length?list.map(r=>'<div class="listrow"><div><span class="tag">'+(r.direction==='outbound'?'Sent':'Received')+'</span> <span class="helper">'+esc(r.created)+' · '+esc(r.status)+'</span><p style="white-space:pre-wrap;margin-top:7px">'+esc(r.body)+'</p></div></div>').join(''):'<p class="helper">No messages with this customer yet.</p>';
   }catch(err){if($('#customer-sms-thread'))$('#customer-sms-thread').textContent=err.message;}
 }
 document.addEventListener('click',async e=>{
   const btn=e.target.closest('[data-sms]');
   if(!btn)return;e.preventDefault();e.stopImmediatePropagation();
   try{if(btn.dataset.sms==='compose')await compose(btn.dataset.contact||'');}catch(err){toast(err.message);}
 },true);
 document.addEventListener('change',async e=>{if(e.target.id==='sms-contact')await thread(e.target.value);},true);
 document.addEventListener('submit',async e=>{
   const form=e.target;if(form.id!=='customer-sms-form')return;
   e.preventDefault();e.stopImmediatePropagation();
   const submit=form.querySelector('button[type=submit]'),error=form.querySelector('.formmessage');
   submit.disabled=true;if(error)error.textContent='';
   const data=Object.fromEntries(new FormData(form));
   try {
     await api('sms_send',data);
     state.records=await api('records');
     toast('Twilio accepted this SMS. Delivery status appears in the conversation.');
     form.elements.message.value='';
     await thread(data.contact);
   }catch(err){if(error)error.textContent=err.message;else toast(err.message);}
   finally{
     const c=byKind('contact').find(r=>r.id===data.contact);
     submit.disabled=!(c&&c.data.sms_consent&&c.data.sms_consent_source&&!c.data.sms_opt_out);
   }
 },true);
})();
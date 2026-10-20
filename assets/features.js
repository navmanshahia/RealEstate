(function EstateEnhancements(){
  'use strict';
  const currentAgent=()=>{
    const slug=new URLSearchParams(location.search).get('agent');
    return state.public.agents.find(x=>x.slug===slug)||state.public.site_agent||null;
  };
  const agentHref=(a,page='home',more='')=>`?agent=${encodeURIComponent(a.slug)}${page==='home'?'':'&page='+encodeURIComponent(page)}${more}`;
  const oldNav=nav,oldFooter=footer,oldPublicPage=publicPage,oldShowProperty=showProperty,oldSettings=settingsPage,oldWorkContent=workContent,oldEditor=editor;
  nav=function(agent){
    if(!agent)return oldNav();
    const home=agentHref(agent),d=agent.data||{};
    const logo=d.logo?`<img class="agent-logo" src="${esc(d.logo)}" alt="${esc(agent.name)} logo">`:esc(agent.name);
    return `<header class="topnav agent-header"><a class="brand" href="${home}">${logo}<span>${esc(d.brokerage||'REAL ESTATE PROFESSIONAL')}</span></a><nav class="navlinks"><a href="${agentHref(agent,'properties')}">My listings</a><a href="${agentHref(agent,'sell')}">Sell with me</a><a href="${agentHref(agent,'contact')}">Contact</a></nav><div class="navright"><a href="${agentHref(agent,'properties','&saved=1')}" class="ghost" aria-label="Saved properties">♡ ${state.fav.length||''}</a>${state.client&&state.client.workspace===agent.id?'<button class="outline small" data-upgrade="client-portal">Client portal</button><button class="ghost small" data-upgrade="client-logout">Sign out</button>':'<button class="outline small" data-upgrade="client-login" data-workspace="'+esc(agent.id)+'">Client sign in</button>'}</div></header>`;
  };
  footer=function(agent){
    if(!agent)return oldFooter();
    return `<footer class="footer"><div><a class="brand" href="${agentHref(agent)}">${esc(agent.name)}</a><p>${esc(agent.data?.brokerage||'Independent real estate professional')}</p></div><div><a href="${agentHref(agent,'properties')}">Listings</a><a href="${agentHref(agent,'sell')}">Sell</a><a href="${agentHref(agent,'contact')}">Contact</a><p>© ${new Date().getFullYear()} ${esc(agent.name)}. Listings shown only with permission.</p><a href="${agentHref(agent,'privacy')}">Privacy information</a></div></footer>`;
  };
  publicPage=function(){
    const slug=new URLSearchParams(location.search).get('agent');
    if(slug||state.public.site_agent)return agentPage(slug||state.public.site_agent.slug);
    return oldPublicPage();
  };
  agentPage=function(slug){
    const a=state.public.agents.find(x=>x.slug===slug);
    if(!a){
      $('#app').innerHTML=oldNav()+'<section class="section"><h1>Agent site unavailable.</h1><p>Contact the agent to confirm their published website.</p></section>'+oldFooter();return;
    }
    const d=a.data||{},page=new URLSearchParams(location.search).get('page')||'home';
    const color=/^#[a-fA-F0-9]{6}$/.test(d.color||'')?d.color:'#153c2d';
    document.documentElement.style.setProperty('--green',color);
    const listings=state.public.properties.filter(p=>p.workspace===a.id);
    let html='';
    if(page==='home'){
      html=`<section class="hero agent-hero"><img class="heroimg" src="${esc(d.hero||photos[0])}" alt="Agent hero image"><div class="herocopy"><div class="eyebrow">${esc(d.area||'LOCAL REAL ESTATE')} · ${esc(d.brokerage||'INDEPENDENT')}</div><h1>${esc(d.headline||'Real estate with a personal touch.')}</h1><p>Work directly with ${esc(a.name)} on your next move.</p><button data-act="enquire" data-id="${esc(a.id)}">Book a consultation →</button></div></section><section class="section"><div class="sectionhead"><div><div class="eyebrow muted">EXPLORE</div><h2>My latest properties.</h2></div><a class="textlink" href="${agentHref(a,'properties')}">View all listings ↗</a></div><div class="propertygrid">${listings.slice(0,3).map(propertyCard).join('')||'<p>No public listings yet. Contact me for current opportunities.</p>'}</div></section>`;
      html+=`<section class="band">${d.headshot?`<img class="agent-headshot" src="${esc(d.headshot)}" alt="${esc(a.name)} headshot">`:`<img src="${photos[3]}" alt="Modern home">`}<div><div class="eyebrow muted">YOUR REAL ESTATE ADVISOR</div><h2>Meet ${esc(a.name)}.</h2><p style="white-space:pre-line">${esc(d.bio||'A personalised approach to real estate in your community.')}</p><p>${esc(d.brokerage||'')}<br>${esc(d.phone||'')}<br>${esc(d.email||'')}</p><a class="btn" href="${agentHref(a,'contact')}">Contact me →</a></div></section>`;
    }else if(page==='properties'){
      const p=new URLSearchParams(location.search);
      let rows=listings.filter(r=>(!p.get('location')||(r.data.location+' '+r.data.name).toLowerCase().includes(p.get('location').toLowerCase()))&&(!p.get('type')||r.data.type===p.get('type'))&&(!p.get('max')||Number(r.data.price)<=Number(p.get('max'))));
      if(p.get('saved'))rows=rows.filter(r=>state.fav.includes(r.id));
      html=`<section class="section"><div class="eyebrow muted">${esc(a.name.toUpperCase())} · PROPERTIES</div><h1>${p.get('saved')?'Your saved homes':'My property collection'}</h1><form id="agentsearch" class="searchbar agent-search"><label>Location<input name="location" value="${esc(p.get('location')||'')}" placeholder="City or neighbourhood"></label><label>Type<select name="type"><option value="">Any type</option>${['House','Condo','Townhouse','Land'].map(t=>`<option ${p.get('type')===t?'selected':''}>${t}</option>`).join('')}</select></label><label>Maximum price<input type="number" name="max" min="0" value="${esc(p.get('max')||'')}" placeholder="No maximum"></label><button>Search</button></form><div class="sectionhead"><p>${rows.length} listing${rows.length===1?'':'s'} found</p><a class="textlink" href="${agentHref(a,'properties')}">Clear filters</a></div><div class="propertygrid">${rows.map(propertyCard).join('')||'<p>No listings match your filters.</p>'}</div>${!state.client?'<p class="helper spacer">Sign in to your client account to save favourites across devices.</p>':''}</section>`;
    }else if(page==='sell'){
      html=`<section class="section"><div class="eyebrow muted">SELL WITH ${esc(a.name.toUpperCase())}</div><h1>Make your next move with confidence.</h1><p>Talk about your home, timing, valuation and marketing plan directly with ${esc(a.name)}.</p><button data-act="enquire" data-id="${esc(a.id)}">Request a selling consultation →</button></section>`;
    }else if(page==='contact'){
      html=`<section class="section"><div class="eyebrow muted">PERSONAL GUIDANCE</div><h1>Get in touch with ${esc(a.name)}.</h1><p>${esc(d.bio||'I look forward to discussing your next home.')}</p><p>${esc(d.phone||'')} ${d.email?` · <a class="textlink" href="mailto:${esc(d.email)}">${esc(d.email)}</a>`:''}</p><button data-act="enquire" data-id="${esc(a.id)}">Send an enquiry →</button></section>`;
    }else{
      html=`<section class="section"><h1>Privacy information</h1><p>Enquiries are shared with ${esc(a.name)}. Client account information and saved listings are tied to this agent website. Contact the agent for access or removal requests.</p><p>Privacy, retention and legal notices must be reviewed by the site owner before launch.</p></section>`;
    }
    $('#app').innerHTML=nav(a)+html+footer(a);
    document.title=`${a.name} · Real estate`;
  };
  showProperty=function(id){
    const a=currentAgent();
    if(a&&!state.public.properties.some(r=>r.id===id&&r.workspace===a.id)){toast('This property is not offered on this agent website.');return;}
    return oldShowProperty(id);
  };
  if(Array.isArray(fields.contact) && !fields.contact.some(x=>x[0]==='marketing_consent'))fields.contact.push(['marketing_consent','Client expressly opted in to marketing emails','checkbox']);
  if(Array.isArray(fields.property) && !fields.property.some(x=>x[0]==='source_url'))fields.property.push(['source_url','Original listing URL (source attribution)']);
  settingsPage=function(){
    const d=state.workspace?.data||{};
    const html=oldSettings();
    const additional=`<label>Brand colour (HEX)<input name="color" pattern="#[A-Fa-f0-9]{6}" placeholder="#153c2d" value="${esc(d.color||'#153c2d')}"></label><label>Timezone<input name="timezone" placeholder="America/Vancouver" value="${esc(d.timezone||'America/Vancouver')}"></label><label class="wide">Logo image URL<input name="logo" value="${esc(d.logo||'')}" placeholder="https://..."></label><label class="wide">Headshot image URL<input name="headshot" value="${esc(d.headshot||'')}" placeholder="https://..."></label><label class="wide">Homepage hero image URL<input name="hero" value="${esc(d.hero||'')}" placeholder="https://..."></label><label class="wide">Inbound email recipient (Mailgun route)<input name="inbound_email" type="email" value="${esc(d.inbound_email||'')}" placeholder="leads@youragency.com"></label><label>SMS lead alerts (E.164)<input name="sms_phone" value="${esc(d.sms_phone||'')}" placeholder="+16045551234"></label>`;
    const withBrand=html.replace('<label class="wide checkboxlabel">',additional+'<label class="wide checkboxlabel">');
    return withBrand+`<div class="panel spacer" style="grid-column:1/-1"><h3>Connect your custom domain</h3><p>Point your domain to the cPanel website with HTTPS enabled. Add the TXT record shown below to prove ownership before activation.</p><form id="domainform"><label>Custom domain<input name="domain" placeholder="homes.yourdomain.com" required></label><button>Request domain verification</button></form><div id="domainresults" class="spacer"></div></div>`;
  };
  workContent=function(){
    oldWorkContent();
    if(state.section==='settings')loadDomain();
    if(state.section==='properties'){
      const headEl=$('#workcontent .actions');
      if(headEl){const btn=document.createElement('button');btn.className='outline';btn.textContent='↗ Import listing URL';btn.dataset.upgrade='import-listing';headEl.prepend(btn);}
      const loc=$('#modal input[name=location]');
      if(loc)setupAddressField(loc);
    }
    if(state.section==='inbox'){
      const pane=$('#workcontent');
      const b=document.createElement('button');b.dataset.upgrade='compose-email';b.className='outline';b.textContent='Compose client email';pane.querySelector('.workhead .actions')?.prepend(b);
      const notice=pane.querySelector('.notice');if(notice)notice.textContent='Outbound email is available when server mail is configured. Inbound mailbox synchronization is not connected; logged activities remain editable.';
    }
    if(state.section==='campaigns'){
      const notice=$('#workcontent .notice');if(notice)notice.textContent='Campaign emails can be sent only to clients who expressly opted in. Unsubscribe links and duplicate-send protection are included. Test your email configuration before use.';
      for(const r of byKind('campaign')){
        const editBtn=Array.from($('#workcontent').querySelectorAll('button[data-act="edit"]')).find(btn=>btn.dataset.id===r.id);
        if(editBtn){const b=document.createElement('button');b.className='outline small';b.dataset.upgrade='send-campaign';b.dataset.id=r.id;b.textContent='Send to opted-in clients';editBtn.after(b);}
      }
    }
    if(state.section==='calendar'){
      const notice=document.createElement('p');notice.className='helper';notice.textContent='Appointments export as .ics files. Automatic Google and Outlook calendar synchronization requires OAuth provider credentials and is not yet connected.';
      $('#workcontent .workhead')?.after(notice);
    }
  };
  editor=function(kind,id,prefill){
    oldEditor(kind,id);
    const form=$('#recordform');
    if(!form)return;
    if(kind==='property'||form.dataset.kind==='property'){
      for(const [key,val] of Object.entries(prefill||{})){
        const field=form.elements.namedItem(key);if(field && typeof val!=='object' && field.type!=='checkbox')field.value=String(val??'');
      }
      const loc=form.elements.namedItem('location');if(loc)setupAddressField(loc);
      const tip=document.createElement('p');tip.className='helper';tip.textContent='Imported descriptions and images must be checked for accuracy, permissions and listing-board rules. An imported listing starts unpublished.';
      form.querySelector('.modalfoot')?.before(tip);
    }
  };
  let addressTimer;
  function setupAddressField(field){
    if(field.dataset.upgradeAddress)return;
    field.dataset.upgradeAddress='1';
    const list=document.createElement('datalist');list.id='estate-addresses';field.setAttribute('list',list.id);field.after(list);
    field.addEventListener('input',()=>{
      clearTimeout(addressTimer);
      if(!state.integrations.address||field.value.length<4)return;
      const query=field.value;
      addressTimer=setTimeout(async()=>{try{const addresses=await api('address_search&q='+encodeURIComponent(query));if(field.value===query)list.innerHTML=addresses.map(x=>`<option value="${esc(x)}"></option>`).join('');}catch(_e){}},750);
    });
  }
  async function loadDomain(){
    if(!$('#domainresults')||state.demo)return;
    try{
      const rows=await api('domain_status'),box=$('#domainresults');if(!box)return;
      box.innerHTML=rows.map(x=>`<p><strong>${esc(x.domain)}</strong> — ${x.verified?'Ownership verified':'Pending verification'}<br><small>Add DNS TXT at <code>_estate-verify.${esc(x.domain)}</code> with value <code>estate=${esc(x.token)}</code></small></p><button class="outline small" data-upgrade="verify-domain">Check DNS ownership</button><p class="helper">Domain verification does not create DNS records, SSL certificates or a cPanel domain alias. Configure those with your hosting provider.</p>`).join('')||'<p class="helper">No custom domain requested yet.</p>';
    }catch(ex){if($('#domainresults'))$('#domainresults').textContent=ex.message;}
  }
  function clientLogin(workspace,register=false){
    const agent=state.public.agents.find(x=>x.id===workspace)||currentAgent();
    modal(register?'Create your client account':'Client sign in',`<p>Save your favourite homes across devices on ${esc(agent?.name||'your agent')}'s website.</p><form id="estateclientform" data-workspace="${esc(workspace)}" data-register="${register?'1':'0'}">${register?'<label>Full name<input name="name" required maxlength="150"></label>':''}<label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" required minlength="${register?'12':'1'}"></label><p class="error formmessage"></p><button>${register?'Create account':'Sign in'}</button></form><button class="ghost" data-upgrade="${register?'client-login':'client-register'}" data-workspace="${esc(workspace)}">${register?'Already have an account? Sign in':'New here? Create client account'}</button>`);
  }
  async function clientPortal(){
    const result=await api('client_portal');state.fav=result.favourites;
    const listings=state.public.properties.filter(p=>result.favourites.includes(p.id));
    modal('Your client portal',`<p>Signed in as ${esc(result.client.name)} (${esc(result.client.email)})</p><h3>Saved homes</h3>${listings.map(p=>`<div class="listrow"><span>${esc(p.data.name)}</span><button class="outline small" data-act="property" data-id="${esc(p.id)}">View</button></div>`).join('')||'<p>No saved homes yet.</p>'}<h3>My enquiries</h3>${result.enquiries.map(e=>`<div class="listrow"><span>${esc(e.created)}</span><span class="tag">${esc(e.status)}</span></div>`).join('')||'<p>No enquiries yet.</p>'}`);
  }
  document.addEventListener('click',async e=>{
    const el=e.target.closest('[data-upgrade],[data-act=heart],[data-act=favourites]');
    if(!el)return;
    const action=el.dataset.upgrade||el.dataset.act;
    if(!el.dataset.upgrade && !['heart','favourites'].includes(action))return;
    e.preventDefault();e.stopImmediatePropagation();
    try{
      if(action==='heart'){
        const id=el.dataset.id,listing=state.public.properties.find(r=>r.id===id)||byKind('property').find(r=>r.id===id);
        if(!listing||listing.workspace==='sample'){toast('Demo listings cannot be saved to an account.');return;}
        if(!state.client||state.client.workspace!==listing.workspace){clientLogin(listing.workspace);return;}
        const result=await api('client_favourite',{id});state.fav=result.saved?[...state.fav,id]:state.fav.filter(x=>x!==id);
        if(currentAgent())agentPage(currentAgent().slug);else publicPage();toast(result.saved?'Saved to your account':'Removed from your account');
      }else if(action==='favourites'){
        const agent=currentAgent();location.href=agent?agentHref(agent,'properties','&saved=1'):'?page=properties&saved=1';
      }else if(action==='client-login'||action==='client-register')clientLogin(el.dataset.workspace||currentAgent()?.id,action==='client-register');
      else if(action==='client-logout'){await api('client_logout',{});state.client=null;state.fav=[];publicPage();toast('Signed out of client account');}
      else if(action==='client-portal')await clientPortal();
      else if(action==='import-listing'){
        modal('Import a property link',`<form id="listingimportform"><label>Source listing URL<input name="url" type="url" pattern="https://.*" placeholder="https://www.realtor.ca/..." required></label><label class="checkboxlabel"><input type="checkbox" name="authorized" value="1" required> I have permission to republish and modify the supplied listing information and photographs.</label><p class="helper">Supports previewing publicly available metadata from REALTOR.ca, RE/MAX, Royal LePage, Century 21 and Coldwell Banker URLs. Some sites block extraction, and their listing-feed/IDX terms still apply.</p><p class="error formmessage"></p><button>Preview listing</button></form>`);
      }else if(action==='verify-domain'){await api('domain_verify',{});await loadDomain();toast('Domain ownership verified. Configure DNS routing and SSL separately.');}
      else if(action==='compose-email'){
        modal('Email a client',`<form id="clientemailform"><label>Contact<select name="contact" required>${byKind('contact').filter(r=>r.data.email).map(r=>`<option value="${esc(r.id)}">${esc(r.data.name)} — ${esc(r.data.email)}</option>`).join('')}</select></label><label>Subject<input name="subject" required maxlength="160"></label><label>Message<textarea name="message" required></textarea></label><p class="error formmessage"></p><button>Send email</button></form>`);
      }else if(action==='send-campaign'){
        if(!confirm('Send this campaign to opted-in clients only (maximum 50 per send)? This will deliver real email if configured.'))return;
        const result=await api('campaign_send',{id:el.dataset.id});toast(`Emails sent: ${result.sent}; failed: ${result.failed}; already sent: ${result.previously_sent}`);
      }
    }catch(ex){toast(ex.message);}
  },true);
  document.addEventListener('submit',async e=>{
    const f=e.target;
    if(!['estateclientform','listingimportform','clientemailform','domainform','agentsearch'].includes(f.id))return;
    e.preventDefault();e.stopImmediatePropagation();
    const data=Object.fromEntries(new FormData(f)),btn=f.querySelector('button[type=submit],button:not([type])');
    if(btn)btn.disabled=true;
    try{
      if(f.id==='estateclientform'){
        await api(f.dataset.register==='1'?'client_register':'client_login',{...data,workspace:f.dataset.workspace});
        state.client=(await api('client_portal')).client;state.fav=(await api('client_portal')).favourites;
        $('#modal').close();publicPage();toast('Client account ready');
      }else if(f.id==='listingimportform'){
        const result=await api('import_listing',{...data,authorized:!!f.elements.authorized.checked});
        $('#modal').close();editor('property',null,result.data);toast(result.note);
      }else if(f.id==='clientemailform'){
        await api('send_contact_email',data);state.records=await api('records');$('#modal').close();workContent();toast('Email accepted by the mail transport');
      }else if(f.id==='domainform'){
        await api('domain_set',data);await loadDomain();toast('Add the verification TXT record to your DNS');
      }else if(f.id==='agentsearch'){
        const a=currentAgent();if(a)location.href=agentHref(a,'properties')+'&'+new URLSearchParams(data);
      }
    }catch(ex){const err=f.querySelector('.formmessage');if(err)err.textContent=ex.message;else toast(ex.message);}
    finally{if(btn)btn.disabled=false;}
  },true);
})();

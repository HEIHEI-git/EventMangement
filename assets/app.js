(() => {
 const menu=document.querySelector('[data-menu-toggle]');if(menu)menu.addEventListener('click',()=>{const open=menu.getAttribute('aria-expanded')==='true';menu.setAttribute('aria-expanded',String(!open));document.getElementById('main-nav').classList.toggle('is-open',!open);});
 document.querySelectorAll('[data-password-toggle]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.passwordToggle),visible=input.type==='password';input.type=visible?'text':'password';button.textContent=visible?'Hide':'Show';button.setAttribute('aria-label',visible?'Hide password':'Show password');button.setAttribute('aria-pressed',String(visible));}));
 const form=document.querySelector('[data-registration]');if(form){const role=form.querySelector('#role'),group=form.querySelector('[data-creator-fields]'),organisation=form.querySelector('#organisation');const update=()=>{group.hidden=role.value!=='creator';organisation.required=role.value==='creator';};role.addEventListener('change',update);update();const pw=form.querySelector('#password'),confirm=form.querySelector('#password-confirm'),feedback=form.querySelector('[data-password-feedback]');const check=()=>{const mismatch=confirm.value!==''&&pw.value!==confirm.value;confirm.setCustomValidity(mismatch?'Passwords do not match.':'');feedback.textContent=mismatch?'Passwords do not match yet.':confirm.value?'Passwords match.':'';};pw.addEventListener('input',check);confirm.addEventListener('input',check);}
 document.querySelectorAll('[data-confirm]').forEach(form=>form.addEventListener('submit',event=>{if(!window.confirm(form.dataset.confirm))event.preventDefault();}));
 document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
 document.querySelectorAll('[data-rail-next]').forEach(button=>button.addEventListener('click',()=>{document.getElementById(button.dataset.railNext).scrollBy({left:360,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});}));
 if(location.pathname.endsWith('/login.php')){const m=location.hash.match(/^#token=(v1\.[0-9]+\.[0-9]+\.[0-9]+\.[a-f0-9]{64})$/);if(m){sessionStorage.setItem('fairboard-scan',m[1]);history.replaceState(null,'',location.pathname+location.search);}}
 const category=document.querySelector('#category');
 if(category){category.addEventListener('change',()=>{if(document.querySelector('input[name="artwork"][value="upload"]:checked'))return;const map={Music:'concert',Film:'cinema',Arts:'gallery',Technology:'digital',Community:'city',Outdoors:'nature',Learning:'learning',Sports:'sports',Social:'social',Other:'upload'};const choice=document.querySelector('input[name="artwork"][value="'+map[category.value]+'"]');if(choice)choice.checked=true;});}
 const eventCoverForm=document.querySelector('[data-event-cover-form]');
 if(eventCoverForm){
  const file=eventCoverForm.querySelector('#artwork-file');
  const radio=eventCoverForm.querySelector('#artwork-upload-radio');
  const trigger=eventCoverForm.querySelector('[data-artwork-upload-trigger]');
  const preview=eventCoverForm.querySelector('[data-artwork-upload-preview]');
  const placeholder=eventCoverForm.querySelector('[data-artwork-upload-placeholder]');
  const hint=eventCoverForm.querySelector('[data-artwork-upload-hint]');
  const feedback=eventCoverForm.querySelector('[data-artwork-upload-feedback]');
  const defaultHelp=feedback.textContent;
  if(!preview.hidden){placeholder.hidden=true;hint.textContent='Change image';}
  const chooseImage=()=>{radio.checked=true;file.click();};
  trigger.addEventListener('click',chooseImage);
  radio.addEventListener('click',()=>file.click());
  file.addEventListener('change',()=>{
   const selected=file.files&&file.files[0];
   if(!selected)return;
   radio.checked=true;
   if(!['image/jpeg','image/png','image/webp'].includes(selected.type)||selected.size>4*1024*1024||selected.size===0){
    feedback.textContent='Choose a JPEG, PNG or WebP image up to 4 MB.';
    file.value='';return;
   }
   // The site's CSP allows data: images but not blob: URLs.
   const reader=new FileReader();
   reader.onload=()=>{if(typeof reader.result==='string'){preview.src=reader.result;preview.hidden=false;placeholder.hidden=true;hint.textContent='Change image';}};
   reader.readAsDataURL(selected);
   feedback.textContent='Selected: '+selected.name+' · Upload will be saved with this event.';
  });
  eventCoverForm.querySelectorAll('input[name="artwork"]:not([value="upload"])').forEach(choice=>choice.addEventListener('change',()=>{
   if(choice.checked){file.value='';feedback.textContent=defaultHelp;}
  }));
 }
 const deletion=document.querySelector('[data-delete-account]');
 if(deletion){const field=deletion.querySelector('[name="confirmation"]'),button=deletion.querySelector('[data-delete-submit]');const update=()=>{button.disabled=field.value!=='Delete';};field.addEventListener('input',update);update();}
})();

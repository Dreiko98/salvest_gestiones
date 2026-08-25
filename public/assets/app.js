document.addEventListener('submit',event=>{
  const form=event.target.closest('.delete-form');
  if(!form)return;
  if(!window.confirm(form.dataset.confirm||'¿Eliminar permanentemente este elemento?')){event.preventDefault();return;}
  form.elements.confirm_delete.value='DELETE';
});
document.addEventListener('submit',event=>{
  const form=event.target.closest('.requeue-form');
  if(!form)return;
  if(!window.confirm(form.dataset.confirm||'¿Volver a procesar esta factura?')){event.preventDefault();return;}
  form.elements.confirm_requeue.value='REQUEUE';
});
document.addEventListener('submit',event=>{
  const form=event.target.closest('.dismiss-form');
  if(!form)return;
  if(!window.confirm(form.dataset.confirm||'¿Confirmas que este correo no contiene ninguna factura?')){event.preventDefault();return;}
  form.elements.confirm_dismiss.value='DISMISS';
});
document.addEventListener('submit',event=>{
  const form=event.target.closest('.purge-form');
  if(!form)return;
  if(!window.confirm(form.dataset.confirm||'¿Eliminar esta factura por completo?')){event.preventDefault();return;}
  form.elements.confirm_purge.value='PURGE';
});
const archivedToggle=document.getElementById('archived-today-toggle');
const archivedPanel=document.getElementById('archived-today-panel');
archivedToggle?.addEventListener('click',()=>{
  const open=archivedPanel.hidden;
  archivedPanel.hidden=!open;
  archivedToggle.setAttribute('aria-expanded',String(open));
});

const menuButton=document.querySelector('.menu-toggle');
const scrim=document.querySelector('.nav-scrim');
const closeMenu=()=>{
  document.body.classList.remove('menu-open');
  menuButton?.setAttribute('aria-expanded','false');
};
menuButton?.addEventListener('click',()=>{
  const open=!document.body.classList.contains('menu-open');
  document.body.classList.toggle('menu-open',open);
  menuButton.setAttribute('aria-expanded',String(open));
  if(open)document.querySelector('.sidebar .nav-link')?.focus();
});
scrim?.addEventListener('click',closeMenu);
document.querySelectorAll('.sidebar a').forEach(link=>link.addEventListener('click',closeMenu));
document.addEventListener('keydown',event=>{if(event.key==='Escape')closeMenu();});

document.querySelectorAll('[data-disclosure]').forEach(panel=>{
  const button=panel.querySelector('.disclosure-toggle');
  const content=panel.querySelector('.disclosure-panel');
  if(!button||!content)return;
  const setOpen=open=>{
    button.setAttribute('aria-expanded',String(open));
    content.hidden=!open;
    if(open){
      const focusTarget=content.querySelector('input,select,textarea,button,a[href]');
      focusTarget?.focus();
    }else{
      button.focus();
    }
  };
  if(panel.dataset.initialOpen==='true')setOpen(true);
  button.addEventListener('click',()=>setOpen(button.getAttribute('aria-expanded')!=='true'));
  panel.querySelectorAll('a.button-secondary').forEach(link=>link.addEventListener('click',()=>setOpen(false)));
  document.addEventListener('keydown',event=>{
    if(event.key==='Escape'&&button.getAttribute('aria-expanded')==='true')setOpen(false);
  });
});

document.addEventListener('click',event=>{
  let button=event.target.closest('.row-toggle');
  if(!button){
    const row=event.target.closest('tr.row-summary');
    if(!row||event.target.closest('.actions'))return;
    button=row.querySelector('.row-toggle');
    if(!button)return;
  }
  const row=button.closest('tr');
  const detail=document.getElementById(button.getAttribute('aria-controls')||'');
  if(!row||!detail)return;
  const open=button.getAttribute('aria-expanded')!=='true';
  button.setAttribute('aria-expanded',String(open));
  detail.hidden=!open;
  row.classList.toggle('row-open',open);
});

document.querySelector('[data-run-worker]')?.addEventListener('click',async event=>{
  const button=event.currentTarget;
  if(button.disabled)return;
  const message=document.getElementById('run-worker-message');
  const setMessage=text=>{if(!message)return;message.textContent=text||'';message.hidden=!text;};
  setMessage('');
  button.disabled=true;
  button.textContent=button.dataset.busyLabel||'Bot ejecutándose…';
  try{
    const response=await fetch('/?route=run-worker',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
      body:new URLSearchParams({csrf:button.dataset.csrf||''}),
    });
    let payload=null;
    try{payload=await response.json();}catch(parseError){payload=null;}
    if(response.status===409){
      setMessage((payload&&payload.message)||'El bot ya se está ejecutando. Espera a que termine.');
      button.disabled=false;
      button.textContent=button.dataset.idleLabel||'Ejecutar bot ahora';
      return;
    }
    if(!response.ok||!payload||payload.status!=='ok'){
      setMessage((payload&&payload.message)||'No se pudo completar la ejecución. Inténtalo de nuevo en unos minutos.');
      button.disabled=false;
      button.textContent=button.dataset.idleLabel||'Ejecutar bot ahora';
      return;
    }
    window.location.reload();
  }catch(networkError){
    setMessage('No se pudo completar la ejecución. Comprueba tu conexión e inténtalo de nuevo.');
    button.disabled=false;
    button.textContent=button.dataset.idleLabel||'Ejecutar bot ahora';
  }
});

// Fase 12: "modo ayuda" — apagado por defecto (cero cambio visual), activado/desactivado con el
// interruptor de la barra lateral, y recordado solo en este navegador (localStorage), nunca
// enviado al servidor. Si localStorage no está disponible (privado, bloqueado), simplemente
// arranca apagado cada vez — nunca debe romper la página por esto.
const helpToggle=document.getElementById('help-toggle');
const HELP_MODE_KEY='salvest-help-mode';
const setHelpMode=on=>{
  document.body.classList.toggle('help-mode',on);
  helpToggle?.setAttribute('aria-pressed',String(on));
  try{localStorage.setItem(HELP_MODE_KEY,on?'1':'0');}catch(storageError){/* modo privado u otro bloqueo: no persiste, no rompe nada */}
};
try{setHelpMode(localStorage.getItem(HELP_MODE_KEY)==='1');}catch(storageError){setHelpMode(false);}
helpToggle?.addEventListener('click',()=>setHelpMode(!document.body.classList.contains('help-mode')));
document.addEventListener('click',event=>{
  const dot=event.target.closest('.help-dot');
  document.querySelectorAll('.help-dot[aria-expanded=true]').forEach(open=>{if(open!==dot)open.setAttribute('aria-expanded','false');});
  if(!dot)return;
  dot.setAttribute('aria-expanded',String(dot.getAttribute('aria-expanded')!=='true'));
});
document.addEventListener('keydown',event=>{
  if(event.key==='Escape')document.querySelectorAll('.help-dot[aria-expanded=true]').forEach(open=>open.setAttribute('aria-expanded','false'));
});

document.addEventListener('toggle',async event=>{
  const node=event.target.closest?.('.folder-node[data-folder-id]');
  if(!node||!node.open||node.dataset.loaded==='true'||node.dataset.loading==='true')return;
  const target=node.querySelector(':scope > .folder-children[data-folder-children]');
  if(!target)return;
  node.dataset.loading='true';
  target.innerHTML='<div class="folder-loading">Cargando...</div>';
  try{
    const params=new URLSearchParams({route:'storage-children',folder_id:node.dataset.folderId,level:node.dataset.level||'1'});
    const response=await fetch('/?'+params.toString(),{headers:{Accept:'application/json'}});
    const payload=await response.json();
    if(!response.ok)throw new Error(payload.error||'No se pudo cargar esta carpeta');
    target.innerHTML=payload.html||'<div class="folder-empty">No hay subcarpetas.</div>';
    node.dataset.loaded='true';
  }catch(error){
    target.innerHTML='<div class="folder-error">'+(error.message||'No se pudo cargar esta carpeta')+'</div>';
  }finally{
    node.dataset.loading='false';
  }
},true);

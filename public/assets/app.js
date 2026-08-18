document.addEventListener('submit',event=>{
  const form=event.target.closest('.delete-form');
  if(!form)return;
  if(!window.confirm(form.dataset.confirm||'¿Eliminar permanentemente este elemento?')){event.preventDefault();return;}
  form.elements.confirm_delete.value='DELETE';
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

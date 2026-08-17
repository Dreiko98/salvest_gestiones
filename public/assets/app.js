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

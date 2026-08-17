document.addEventListener('submit',event=>{
  const form=event.target.closest('.delete-form');
  if(!form)return;
  if(!window.confirm(form.dataset.confirm||'¿Eliminar permanentemente este elemento?')){event.preventDefault();return;}
  form.elements.confirm_delete.value='DELETE';
});

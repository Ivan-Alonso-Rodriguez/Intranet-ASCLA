/* Restrict reusable runners to the published QA or an explicit local fixture. */
module.exports=function validateAccess(access){
  const local=access.origin==='ascla-local-test-users'&&['localhost','127.0.0.1'].includes(new URL(access.base).hostname);
  const remote=access.origin==='ascla-shared-test-users'&&access.base==='https://wordpress.ingsoftware.lat';
  if(!local&&!remote)throw Error('Se requiere un acceso de QA publicado o una fixture local explícita.');
  for(const name of ['Ana','Bruno','Carla','Administrador']){
    const actor=access.actors?.[name];
    if(!actor?.password||!actor.login||!Number.isInteger(actor.id)||actor.id<1)throw Error('Falta el acceso de '+name);
    if(name!=='Administrador'&&actor.login!=='prueba.ascla.'+name.toLowerCase())throw Error('Solo se utilizan cuentas dedicadas de prueba.');
  }
  if(new Set(Object.values(access.actors).map(actor=>actor.id)).size!==4)throw Error('Se requieren cuatro identidades distintas.');
  return local?'local-shared-test-users':'remote-shared-test-users';
};

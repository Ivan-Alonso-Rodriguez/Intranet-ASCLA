/* Follow the real browser-bound WordPress login handshake; never bypass CSRF. */
module.exports=async function nativeLogin(base,actor){
 const options={redirect:'manual',signal:AbortSignal.timeout(45000)};
 const form=await fetch(base+'/wp-login.php',options),html=await form.text();
 const nonce=html.match(/name="_ascla_native_login_nonce"\s+value="([^"]+)"/)?.[1];
 if(form.status!==200||!nonce)throw Error('No se pudo preparar el formulario de acceso.');
 const guest=form.headers.getSetCookie().map(value=>value.split(';')[0]).join('; ');
 const response=await fetch(base+'/wp-login.php',{...options,signal:AbortSignal.timeout(45000),method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Cookie:guest},body:new URLSearchParams({log:actor.login,pwd:actor.password,testcookie:'1',_ascla_native_login_nonce:nonce,redirect_to:base+'/intranet/'})});
 if(response.status!==302)throw Error('Login de preparación rechazado (HTTP '+response.status+').');
 const cookie=[guest,...response.headers.getSetCookie().map(value=>value.split(';')[0])].filter(Boolean).join('; ');
 const page=await fetch(base+'/intranet/',{headers:{Cookie:cookie},signal:AbortSignal.timeout(45000)}),match=(await page.text()).match(/var ASCLA\s*=\s*(\{[\s\S]*?\});/);
 if(!match)throw Error('No se encontró la sesión REST autorizada.');
 return {cookie,nonce:JSON.parse(match[1]).nonce};
};

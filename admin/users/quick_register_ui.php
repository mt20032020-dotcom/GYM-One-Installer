<style>
#qrAvatar {
  position: relative; width: 100px; height: 100px; border-radius: 50%;
  background: #f1f5fb; border: 2px dashed #b9cdf5; cursor: pointer;
  display: flex; align-items: center; justify-content: center; overflow: hidden;
  margin: 0 auto 10px;
}
#qrAvatar.has-img { border-style: solid; border-color: #e53935; }
#qrAvatarImg { width:100%; height:100%; object-fit:cover; display:none; }
#qrAvatar.has-img #qrAvatarImg { display:block; }
#qrAvatar.has-img .qr-avatar-placeholder { display:none; }
.qr-avatar-placeholder { color:#6f8bc4; font-size:11px; text-align:center; }
#qrWebcamPanel video { width:100%; border-radius:10px; margin-top:8px; }
</style>

<button type="button" class="btn btn-danger btn-block mt-1" data-toggle="modal" data-target="#quickRegisterModal">
  <i class="bi bi-person-plus"></i> Registrar nuevo
</button>

<div class="modal fade" id="quickRegisterModal" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Registrar nuevo socio</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <div id="qrAlert" style="display:none;" class="alert"></div>
        <div style="text-align:center;">
          <label id="qrAvatar" for="qrPhoto">
            <img id="qrAvatarImg" src="" alt="">
            <span class="qr-avatar-placeholder"><i class="bi bi-camera" style="font-size:22px;"></i><br>Foto</span>
          </label>
          <input type="file" id="qrPhoto" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none;">
          <br>
          <button type="button" class="btn btn-sm btn-default" id="qrBtnWebcam"><i class="bi bi-camera-fill"></i> Usar c&aacute;mara</button>
          <div id="qrWebcamPanel" style="display:none;">
            <video id="qrWebcamVideo" autoplay playsinline></video>
            <div style="margin-top:6px;">
              <button type="button" class="btn btn-sm btn-primary" id="qrBtnCapture">Capturar</button>
              <button type="button" class="btn btn-sm btn-default" id="qrBtnWebcamCancel">Cancelar</button>
            </div>
          </div>
        </div>
        <hr>
        <div style="display:flex;gap:10px;">
          <div class="form-group" style="flex:1;">
            <label>Nombre(s)</label>
            <input type="text" class="form-control" id="qrFirstname">
          </div>
          <div class="form-group" style="flex:1;">
            <label>Apellido</label>
            <input type="text" class="form-control" id="qrLastname">
          </div>
        </div>
        <div class="form-group">
          <label>C&eacute;dula</label>
          <input type="text" class="form-control" id="qrCedula" pattern="[0-9]+">
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" class="form-control" id="qrEmail">
        </div>
        <div style="display:flex;gap:10px;">
          <div class="form-group" style="flex:1;">
            <label>Celular</label>
            <input type="tel" class="form-control" id="qrCelular">
          </div>
          <div class="form-group" style="flex:1;">
            <label>Barrio</label>
            <input type="text" class="form-control" id="qrBarrio">
          </div>
        </div>
        <div style="display:flex;gap:10px;">
          <div class="form-group" style="flex:1;">
            <label>G&eacute;nero</label>
            <select class="form-control" id="qrGender">
              <option value="Male">Masculino</option>
              <option value="Female">Femenino</option>
              <option value="Other">Otro</option>
            </select>
          </div>
          <div class="form-group" style="flex:1;">
            <label>Fecha de nacimiento</label>
            <input type="date" class="form-control" id="qrBirthdate">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" id="qrSubmitBtn">
          <i class="bi bi-box-arrow-in-right"></i> Registrar y continuar
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var photoInput = document.getElementById('qrPhoto');
  var avatar = document.getElementById('qrAvatar');
  var avatarImg = document.getElementById('qrAvatarImg');
  var objUrl = null;
  var capturedBlob = null;

  avatar.addEventListener('click', function(){ photoInput.click(); });
  photoInput.addEventListener('change', function(){
    var f = photoInput.files[0];
    if(!f) return;
    capturedBlob = f;
    if(objUrl) URL.revokeObjectURL(objUrl);
    objUrl = URL.createObjectURL(f);
    avatarImg.src = objUrl;
    avatar.classList.add('has-img');
  });

  var btnCam = document.getElementById('qrBtnWebcam');
  var panel = document.getElementById('qrWebcamPanel');
  var video = document.getElementById('qrWebcamVideo');
  var btnCap = document.getElementById('qrBtnCapture');
  var btnCancel = document.getElementById('qrBtnWebcamCancel');
  var stream = null;
  function stopCam(){ if(stream){ stream.getTracks().forEach(function(t){t.stop();}); stream=null; } panel.style.display='none'; }
  btnCam.addEventListener('click', function(){
    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){ alert('Este navegador no soporta camara'); return; }
    navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:720},height:{ideal:960}},audio:false})
      .then(function(s){ stream=s; video.srcObject=s; panel.style.display='block'; })
      .catch(function(){ alert('No se pudo acceder a la camara.'); });
  });
  btnCancel.addEventListener('click', stopCam);
  btnCap.addEventListener('click', function(){
    var c = document.createElement('canvas');
    c.width = video.videoWidth; c.height = video.videoHeight;
    var ctx = c.getContext('2d');
    ctx.translate(c.width,0); ctx.scale(-1,1);
    ctx.drawImage(video,0,0);
    c.toBlob(function(blob){
      capturedBlob = new File([blob], 'captura.png', {type:'image/png'});
      if(objUrl) URL.revokeObjectURL(objUrl);
      objUrl = URL.createObjectURL(capturedBlob);
      avatarImg.src = objUrl;
      avatar.classList.add('has-img');
      stopCam();
    }, 'image/png');
  });

  var alertBox = document.getElementById('qrAlert');
  function showAlert(msg, ok){
    alertBox.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger');
    alertBox.textContent = msg;
    alertBox.style.display = 'block';
  }

  document.getElementById('qrSubmitBtn').addEventListener('click', function(){
    var btn = this;
    var firstname = document.getElementById('qrFirstname').value.trim();
    var lastname = document.getElementById('qrLastname').value.trim();
    var cedula = document.getElementById('qrCedula').value.trim();
    var email = document.getElementById('qrEmail').value.trim();

    if(!firstname || !lastname || !cedula || !email){
      showAlert('Completa nombre, apellido, cedula y email.', false);
      return;
    }
    if(!capturedBlob){
      showAlert('La foto de perfil es obligatoria.', false);
      return;
    }

    var fd = new FormData();
    fd.append('firstname', firstname);
    fd.append('lastname', lastname);
    fd.append('cedula', cedula);
    fd.append('email', email);
    fd.append('celular', document.getElementById('qrCelular').value.trim());
    fd.append('barrio', document.getElementById('qrBarrio').value.trim());
    fd.append('gender', document.getElementById('qrGender').value);
    fd.append('birthdate', document.getElementById('qrBirthdate').value);
    fd.append('profile_photo', capturedBlob, 'foto.png');

    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Registrando...';

    fetch('quick_register/index.php', { method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(d.ok){
          if(d.enroll_ok === false){
            showAlert('Registrado, pero fallo la sincronizacion con el torniquete. Avisa para revisarlo. Redirigiendo...', true);
          } else {
            showAlert('Registrado! Redirigiendo a elegir plan...', true);
          }
          setTimeout(function(){
            window.location.href = '../boss/sell/ticket/?userid=' + encodeURIComponent(d.userid);
          }, d.enroll_ok === false ? 2500 : 900);
        } else {
          showAlert(d.error || 'Error desconocido', false);
          btn.disabled = false;
          btn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Registrar y continuar';
        }
      })
      .catch(function(){
        showAlert('Error de red al registrar.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-box-arrow-in-right"></i> Registrar y continuar';
      });
  });
})();
</script>

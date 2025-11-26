<style>
    body {
  background: #181818;
  display: flex;
  height: 100vh;
  align-items: center;
}

.container {
  width: 400px;
  display: flex;
  flex-direction: column;
  margin: 0 auto;
}

input, button {
  height: 65px;
  border-radius: 5px;
  padding: 0 20px;
  font-size: 1.2rem;
  border: 0;
}

input {
  background: #2E2E2E;
  margin-bottom: 2px;
}

button {
  margin: 20px 0;
  background: none;
  border: 2px solid #2e2e2e;
  color: #aaa;
}

a {
  margin: 20px auto;
  font-family: 'Roboto', sans-serif;
  color: #fff;
  font-weight: bold;
}

.forgot-password {
  color: #aaa;
  font-weight: normal;
}

img {
  width: 100%;
}


</style>
<div class="container">
  <input id="_token" type="password" placeholder="Token" />
  <button style="cursor: pointer;" id="entrar">Entrar</button>
</div>
@if(Auth::user())
<script>window.location = "/master/dashboard"</script>
@endif
<script src="/libs/jquery.js"></script>
<script>
    $("#entrar").off('click').on('click', function(e){
        if($("#_token").val().length < 4){
            alert('Token inválido.');
            return;
        }

        $.post('/master/autenticausuario', { token: $("#_token").val() }, (r)=>{
            if(r.status == 200) location.href = '/master/dashboard';
            else alert('Token inválido!!!');
        })
    })
</script>

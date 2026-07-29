const views={join:document.querySelector('#joinView'),lobby:document.querySelector('#lobbyView'),hand:document.querySelector('#handView')};
const roomInput=document.querySelector('#roomCode');
const nameInput=document.querySelector('#playerName');
const joinButton=document.querySelector('#joinButton');
const joinError=document.querySelector('#joinError');
const roomLabel=document.querySelector('#roomLabel');
const nameLabel=document.querySelector('#nameLabel');
const avatar=document.querySelector('#avatar');
const demoButton=document.querySelector('#demoButton');
const handEl=document.querySelector('#hand');
const playButton=document.querySelector('#playButton');
const drawButton=document.querySelector('#drawButton');
const leaveButton=document.querySelector('#leaveButton');
const feedback=document.querySelector('#feedback');
let selected=-1;
let roomCode='';
let playerToken='';
let pollTimer=null;
let cards=[{color:'green',value:'4'},{color:'gold-card',value:'6'},{color:'red',value:'9'},{color:'green',value:'REVERSE'},{color:'wild',value:'WILD'},{color:'blue',value:'+2'}];

function show(name){Object.values(views).forEach(v=>v.classList.remove('active'));views[name].classList.add('active')}
function normalizedCode(){return roomInput.value.trim().toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4)}
roomInput.addEventListener('input',()=>{roomInput.value=normalizedCode()});

async function api(path,options={}){
  const response=await fetch(path,{headers:{'content-type':'application/json'},...options});
  const data=await response.json().catch(()=>({}));
  if(!response.ok)throw new Error(data.error||'Unable to connect');
  return data;
}

joinButton.addEventListener('click',async()=>{
  const code=normalizedCode();
  const player=nameInput.value.trim();
  if(code.length!==4){joinError.textContent='Enter the four-character code from the TV.';return}
  if(player.length<2){joinError.textContent='Enter a player name.';return}
  joinButton.disabled=true;
  joinError.textContent='Connecting...';
  try{
    const result=await api(`/api/rooms/${code}/join`,{method:'POST',body:JSON.stringify({name:player})});
    roomCode=code;
    playerToken=result.player.token;
    roomLabel.textContent=code;
    nameLabel.textContent=result.player.name.toUpperCase();
    avatar.textContent=result.player.name.charAt(0).toUpperCase();
    joinError.textContent='';
    show('lobby');
    beginPolling();
  }catch(error){joinError.textContent=error.message}
  finally{joinButton.disabled=false}
});

function beginPolling(){
  clearInterval(pollTimer);
  pollTimer=setInterval(async()=>{
    try{
      const room=await api(`/api/rooms/${roomCode}`);
      if(room.status==='playing'){
        clearInterval(pollTimer);
        renderHand();
        show('hand');
        feedback.textContent='The host started the game.';
      }
    }catch(error){feedback.textContent=error.message}
  },1000);
}

demoButton.addEventListener('click',()=>{renderHand();show('hand')});
function renderHand(){handEl.innerHTML='';cards.forEach((card,index)=>{const button=document.createElement('button');button.type='button';button.className=`card ${card.color}${selected===index?' selected':''}`;button.textContent=card.value;button.setAttribute('aria-label',`${card.color.replace('-card','')} ${card.value}`);button.addEventListener('click',()=>{selected=index;feedback.textContent=`Selected ${card.value}.`;playButton.disabled=false;renderHand()});handEl.appendChild(button)})}
playButton.addEventListener('click',()=>{if(selected<0)return;const [played]=cards.splice(selected,1);feedback.textContent=`Played ${played.value}. Live card commands arrive in Sprint 8B.`;selected=-1;playButton.disabled=true;renderHand()});
drawButton.addEventListener('click',()=>{const pool=[{color:'red',value:'3'},{color:'blue',value:'7'},{color:'gold-card',value:'SKIP'},{color:'green',value:'+2'}];cards.push(pool[Math.floor(Math.random()*pool.length)]);feedback.textContent='Demo card drawn. Live dealing arrives in Sprint 8B.';selected=-1;playButton.disabled=true;renderHand()});
leaveButton.addEventListener('click',()=>{clearInterval(pollTimer);selected=-1;roomCode='';playerToken='';roomInput.value='';nameInput.value='';show('join')});

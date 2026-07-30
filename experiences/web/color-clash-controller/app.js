const views={join:document.querySelector('#joinView'),lobby:document.querySelector('#lobbyView'),hand:document.querySelector('#handView')};
const roomInput=document.querySelector('#roomCode');
const nameInput=document.querySelector('#playerName');
const joinButton=document.querySelector('#joinButton');
const joinError=document.querySelector('#joinError');
const roomLabel=document.querySelector('#roomLabel');
const nameLabel=document.querySelector('#nameLabel');
const avatar=document.querySelector('#avatar');
const handEl=document.querySelector('#hand');
const playButton=document.querySelector('#playButton');
const drawButton=document.querySelector('#drawButton');
const leaveButton=document.querySelector('#leaveButton');
const feedback=document.querySelector('#feedback');
const turnText=document.querySelector('#turnText');
const activeColor=document.querySelector('#activeColor');
let selected=-1;
let roomCode='';
let playerToken='';
let pollTimer=null;
let state=null;

function show(name){Object.values(views).forEach(v=>v.classList.remove('active'));views[name].classList.add('active')}
function normalizedCode(){return roomInput.value.trim().toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4)}
roomInput.addEventListener('input',()=>{roomInput.value=normalizedCode()});

const roomFromLink=new URLSearchParams(window.location.search).get('room');
if(roomFromLink){
  roomInput.value=String(roomFromLink).toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4);
  nameInput.focus();
}

async function api(path,options={}){
  const headers={'content-type':'application/json',...(playerToken?{'x-player-token':playerToken}:{})};
  const response=await fetch(path,{headers,...options});
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
  refreshState();
  pollTimer=setInterval(refreshState,700);
}

async function refreshState(){
  if(!roomCode||!playerToken)return;
  try{
    state=await api(`/api/rooms/${roomCode}/state`);
    if(state.room.status==='playing'||state.room.status==='finished'){
      show('hand');
      renderState();
    }
  }catch(error){feedback.textContent=error.message}
}

function cardClass(card){
  if(card.color==='WILD')return 'wild';
  if(card.color==='GOLD')return 'gold-card';
  return card.color.toLowerCase();
}

function renderState(){
  if(!state)return;
  const room=state.room;
  const player=state.player;
  const game=room.game;
  activeColor.textContent=game?.activeColor||'—';
  activeColor.className=`color-chip ${(game?.activeColor||'gold').toLowerCase()}`;
  if(room.status==='finished'){
    const winner=room.players.find(item=>item.id===game.winnerId);
    turnText.textContent=winner?.id===player.id?'You win!':`${winner?.name||'Player'} wins`;
    feedback.textContent=game.message;
  }else{
    turnText.textContent=player.isTurn?'Your turn':'Waiting for your turn';
    feedback.textContent=game.message||'Choose a matching card.';
  }
  playButton.disabled=!player.isTurn||selected<0||room.status!=='playing';
  drawButton.disabled=!player.isTurn||room.status!=='playing';
  renderHand(player.hand);
}

function renderHand(cards){
  handEl.innerHTML='';
  cards.forEach((card,index)=>{
    const button=document.createElement('button');
    button.type='button';
    button.className=`card ${cardClass(card)}${selected===index?' selected':''}`;
    button.textContent=card.value;
    button.setAttribute('aria-label',`${card.color} ${card.value}`);
    button.addEventListener('click',()=>{
      selected=index;
      feedback.textContent=`Selected ${card.value}.`;
      renderState();
    });
    handEl.appendChild(button);
  });
}

playButton.addEventListener('click',async()=>{
  if(!state||selected<0)return;
  const card=state.player.hand[selected];
  let chosenColor='';
  if(card.value==='WILD'){
    chosenColor=(window.prompt('Choose RED, GREEN, BLUE, or GOLD','RED')||'RED').trim().toUpperCase();
    if(!['RED','GREEN','BLUE','GOLD'].includes(chosenColor))chosenColor='RED';
  }
  playButton.disabled=true;
  try{
    state=await api(`/api/rooms/${roomCode}/play`,{method:'POST',body:JSON.stringify({cardId:card.id,chosenColor})});
    selected=-1;
    renderState();
  }catch(error){feedback.textContent=error.message;renderState()}
});

drawButton.addEventListener('click',async()=>{
  drawButton.disabled=true;
  try{
    state=await api(`/api/rooms/${roomCode}/draw`,{method:'POST',body:'{}'});
    selected=-1;
    renderState();
  }catch(error){feedback.textContent=error.message;renderState()}
});

leaveButton.addEventListener('click',()=>{
  clearInterval(pollTimer);
  selected=-1;
  roomCode='';
  playerToken='';
  state=null;
  roomInput.value='';
  nameInput.value='';
  show('join');
});

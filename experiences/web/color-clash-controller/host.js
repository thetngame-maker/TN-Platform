const roomCodeEl=document.querySelector('#roomCode');
const statusEl=document.querySelector('#status');
const playersEl=document.querySelector('#players');
const startButton=document.querySelector('#startButton');
const newButton=document.querySelector('#newButton');
let roomCode='';
let timer=null;

async function api(path,options={}){
  const response=await fetch(path,{headers:{'content-type':'application/json'},...options});
  const data=await response.json().catch(()=>({}));
  if(!response.ok)throw new Error(data.error||'Request failed');
  return data;
}

function render(room){
  roomCodeEl.textContent=room.code;
  playersEl.innerHTML='';
  room.players.forEach(player=>{
    const item=document.createElement('div');
    item.className='player';
    item.textContent=`✓ ${player.name}`;
    playersEl.appendChild(item);
  });
  statusEl.textContent=room.status==='playing'?'Game started':`${room.players.length} player${room.players.length===1?'':'s'} connected`;
  startButton.disabled=room.players.length<1||room.status!=='lobby';
}

async function createRoom(){
  clearInterval(timer);
  statusEl.textContent='Creating room…';
  try{
    const room=await api('/api/rooms',{method:'POST',body:'{}'});
    roomCode=room.code;
    render(room);
    timer=setInterval(refresh,1000);
  }catch(error){statusEl.textContent=error.message}
}

async function refresh(){
  if(!roomCode)return;
  try{render(await api(`/api/rooms/${roomCode}`))}
  catch(error){statusEl.textContent=error.message}
}

startButton.addEventListener('click',async()=>{
  startButton.disabled=true;
  try{render(await api(`/api/rooms/${roomCode}/start`,{method:'POST',body:'{}'}))}
  catch(error){statusEl.textContent=error.message;startButton.disabled=false}
});
newButton.addEventListener('click',createRoom);
createRoom();

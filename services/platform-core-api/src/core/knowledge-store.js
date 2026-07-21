import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const DATA_DIR = process.env.DATA_DIR || path.join(process.cwd(), 'data');
const DATA_FILE = path.join(DATA_DIR, 'knowledge.json');
const PREFIX = { artist:'ART', venue:'VEN', destination:'DST', event:'EVT', trail:'TRL', waterfall:'WTR', restaurant:'RST', lodging:'LDG', campground:'CMP', business:'BUS', photo:'MED', review:'REV', historic_site:'HST', museum:'MUS', festival:'FST' };

function now(){ return new Date().toISOString(); }
function clone(v){ return JSON.parse(JSON.stringify(v)); }
function slugify(v){ return String(v||'').toLowerCase().trim().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,''); }
function token(){ return crypto.randomBytes(5).toString('hex').toUpperCase(); }
function idFor(type){ return `${PREFIX[type] || 'ENT'}_${token()}`; }
function relId(){ return `REL_${token()}`; }

const empty = { schema_version:1, entities:[], relationships:[], versions:[] };
let state;
function load(){
  if(state) return state;
  fs.mkdirSync(DATA_DIR,{recursive:true});
  try { state = JSON.parse(fs.readFileSync(DATA_FILE,'utf8')); }
  catch { state = clone(empty); persist(); }
  return state;
}
function persist(){
  fs.mkdirSync(DATA_DIR,{recursive:true});
  const tmp = `${DATA_FILE}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(state,null,2));
  fs.renameSync(tmp, DATA_FILE);
}
function version(entity, action, changed_fields=[]){
  load().versions.push({ id:`VER_${token()}`, entity_id:entity.id, action, changed_fields, snapshot:clone(entity), created_at:now() });
}
function normalizeSources(sources=[]){ return Array.isArray(sources) ? sources.map(s=>({name:String(s.name||'manual'),url:String(s.url||''),confidence:Number.isFinite(+s.confidence)?Math.max(0,Math.min(1,+s.confidence)):1,verified_at:s.verified_at||now()})) : []; }

export function stats(){ const s=load(); return {entities:s.entities.length,relationships:s.relationships.length,versions:s.versions.length,entity_types:[...new Set(s.entities.map(e=>e.type))].sort()}; }
export function listEntities({type='',q='',status='',limit=100,offset=0}={}){
  let rows=load().entities;
  if(type) rows=rows.filter(e=>e.type===type);
  if(status) rows=rows.filter(e=>e.status===status);
  if(q){ const n=q.toLowerCase(); rows=rows.filter(e=>[e.id,e.title,e.slug,...(e.aliases||[])].join(' ').toLowerCase().includes(n)); }
  return {items:clone(rows.slice(offset,offset+limit)),total:rows.length,limit,offset};
}
export function getEntity(id){ const e=load().entities.find(x=>x.id===id); if(!e) return null; return {...clone(e),relationships:listRelationships({entity_id:id}).items,versions:clone(load().versions.filter(v=>v.entity_id===id).slice(-25).reverse())}; }
export function createEntity(input={}){
  const type=slugify(input.type).replace(/-/g,'_'); const title=String(input.title||'').trim();
  if(!type||!title) throw new Error('type and title are required');
  const duplicate=load().entities.find(e=>e.type===type && e.title.toLowerCase()===title.toLowerCase());
  if(duplicate) return {entity:clone(duplicate),created:false};
  const t=now(); const entity={id:idFor(type),type,title,slug:slugify(input.slug||title),status:input.status||'draft',confidence:Number.isFinite(+input.confidence)?Math.max(0,Math.min(1,+input.confidence)):1,aliases:Array.isArray(input.aliases)?input.aliases.map(String):[],metadata:input.metadata&&typeof input.metadata==='object'?input.metadata:{},sources:normalizeSources(input.sources),created_at:t,updated_at:t,verified_at:input.verified_at||null};
  load().entities.push(entity); version(entity,'created',Object.keys(entity)); persist(); return {entity:clone(entity),created:true};
}
export function updateEntity(id,input={}){
  const entity=load().entities.find(e=>e.id===id); if(!entity) return null;
  const allowed=['title','slug','status','confidence','aliases','metadata','sources','verified_at']; const changed=[];
  for(const key of allowed){ if(Object.hasOwn(input,key)){ entity[key]=key==='sources'?normalizeSources(input[key]):input[key]; changed.push(key); } }
  if(changed.includes('title')&&!changed.includes('slug')) entity.slug=slugify(entity.title);
  entity.updated_at=now(); version(entity,'updated',changed); persist(); return clone(entity);
}
export function deleteEntity(id){ const s=load(); const before=s.entities.length; s.entities=s.entities.filter(e=>e.id!==id); s.relationships=s.relationships.filter(r=>r.from_id!==id&&r.to_id!==id); if(s.entities.length===before)return false; persist();return true; }
export function listRelationships({entity_id='',type='',limit=200,offset=0}={}){ let rows=load().relationships; if(entity_id) rows=rows.filter(r=>r.from_id===entity_id||r.to_id===entity_id); if(type)rows=rows.filter(r=>r.type===type); return {items:clone(rows.slice(offset,offset+limit)),total:rows.length,limit,offset}; }
export function createRelationship(input={}){
  const from_id=String(input.from_id||''),to_id=String(input.to_id||''),type=slugify(input.type).replace(/-/g,'_');
  if(!from_id||!to_id||!type) throw new Error('from_id, to_id and type are required');
  if(from_id===to_id) throw new Error('A relationship cannot point to itself');
  const entities=load().entities; if(!entities.some(e=>e.id===from_id)||!entities.some(e=>e.id===to_id)) throw new Error('Both entities must exist');
  const duplicate=load().relationships.find(r=>r.from_id===from_id&&r.to_id===to_id&&r.type===type); if(duplicate)return {relationship:clone(duplicate),created:false};
  const t=now(); const relationship={id:relId(),from_id,to_id,type,confidence:Number.isFinite(+input.confidence)?Math.max(0,Math.min(1,+input.confidence)):1,metadata:input.metadata&&typeof input.metadata==='object'?input.metadata:{},sources:normalizeSources(input.sources),created_at:t,updated_at:t,verified_at:input.verified_at||null};
  load().relationships.push(relationship); persist(); return {relationship:clone(relationship),created:true};
}
export function deleteRelationship(id){const s=load();const before=s.relationships.length;s.relationships=s.relationships.filter(r=>r.id!==id);if(s.relationships.length===before)return false;persist();return true;}
export function graph(id,{depth=1,limit=100}={}){
  const s=load(); const root=s.entities.find(e=>e.id===id); if(!root)return null;
  const seen=new Set([id]), nodes=[clone(root)], edges=[]; let frontier=[id];
  for(let d=0;d<Math.min(3,depth)&&frontier.length;d++){ const next=[]; for(const eid of frontier){ for(const r of s.relationships){ if(r.from_id!==eid&&r.to_id!==eid)continue; if(edges.length>=limit)break; if(!edges.some(x=>x.id===r.id))edges.push(clone(r)); const other=r.from_id===eid?r.to_id:r.from_id; if(!seen.has(other)){seen.add(other);next.push(other);const e=s.entities.find(x=>x.id===other);if(e)nodes.push(clone(e));} } } frontier=next; }
  return {root_id:id,nodes,edges};
}
export function seed(){ if(load().entities.length)return stats(); const dst=createEntity({type:'destination',title:'Pelham',status:'verified',confidence:1,sources:[{name:'manual'}]}).entity; const ven=createEntity({type:'venue',title:'The Caverns',status:'verified',confidence:1,metadata:{city:'Pelham',state:'TN'},sources:[{name:'manual'}]}).entity; createRelationship({from_id:ven.id,to_id:dst.id,type:'located_in',confidence:1,sources:[{name:'manual'}]}); return stats(); }

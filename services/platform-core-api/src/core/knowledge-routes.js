import { Router } from 'express';
import { requireApiKey } from '../security.js';
import * as store from './knowledge-store.js';

export const knowledgeRouter=Router();
knowledgeRouter.use(requireApiKey);
const ok=(res,data,status=200)=>res.status(status).json({ok:true,data});
const fail=(res,error,status=400)=>res.status(status).json({ok:false,error});
knowledgeRouter.get('/stats',(_req,res)=>ok(res,store.stats()));
knowledgeRouter.get('/entities',(req,res)=>ok(res,store.listEntities({type:req.query.type||'',q:req.query.q||'',status:req.query.status||'',limit:Math.min(500,+(req.query.limit||100)),offset:+(req.query.offset||0)})));
knowledgeRouter.post('/entities',(req,res)=>{try{const out=store.createEntity(req.body);ok(res,out,out.created?201:200);}catch(e){fail(res,e.message);}});
knowledgeRouter.get('/entities/:id',(req,res)=>{const out=store.getEntity(req.params.id);out?ok(res,out):fail(res,'Entity not found',404);});
knowledgeRouter.patch('/entities/:id',(req,res)=>{try{const out=store.updateEntity(req.params.id,req.body);out?ok(res,out):fail(res,'Entity not found',404);}catch(e){fail(res,e.message);}});
knowledgeRouter.delete('/entities/:id',(req,res)=>store.deleteEntity(req.params.id)?ok(res,{deleted:true}):fail(res,'Entity not found',404));
knowledgeRouter.get('/relationships',(req,res)=>ok(res,store.listRelationships({entity_id:req.query.entity_id||'',type:req.query.type||'',limit:Math.min(500,+(req.query.limit||200)),offset:+(req.query.offset||0)})));
knowledgeRouter.post('/relationships',(req,res)=>{try{const out=store.createRelationship(req.body);ok(res,out,out.created?201:200);}catch(e){fail(res,e.message);}});
knowledgeRouter.delete('/relationships/:id',(req,res)=>store.deleteRelationship(req.params.id)?ok(res,{deleted:true}):fail(res,'Relationship not found',404));
knowledgeRouter.get('/graph/:id',(req,res)=>{const out=store.graph(req.params.id,{depth:+(req.query.depth||1),limit:+(req.query.limit||100)});out?ok(res,out):fail(res,'Entity not found',404);});
knowledgeRouter.post('/seed',(_req,res)=>ok(res,store.seed()));

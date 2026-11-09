/**
 * Topic-independent authoring helpers. Output is the existing bounded scene vocabulary.
 * No renderer extensions, SVG injection, DOM attributes or subject-specific primitives.
 */
export const round = n => Math.round(n * 1000) / 1000;
export const rng = (seed = 7) => () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 4294967296);
export const commands = d => {
    const tokens = d.match(/[MLQCZ]|-?(?:\d*\.?\d+)(?:e[-+]?\d+)?/gi);
    const lengths = {M:2,L:2,Q:4,C:6,Z:0};
    const segments = [];
    for(let i=0;i<tokens.length;){
        const command = tokens[i++].toUpperCase();
        if(!(command in lengths)) throw new Error('Only explicit absolute M/L/Q/C/Z commands are supported');
        segments.push({command, values:tokens.slice(i,i+lengths[command]).map(Number)});
        i += lengths[command];
    }
    return segments;
};
export class Illustration {
    constructor(title, description, palette) {
        this.scene = {
            type:'animated_scene',version:'1.0',title,description,representation:'schematic',
            viewBox:[0,0,1200,720],aspectRatio:'meet',
            style:{reference:'The living watershed · illustrated scientific atlas',artDirection:'Semi-realistic layered vector landscape. Atmospheric mountain facets, sculpted foliage, restrained water highlights and precise scientific magnifications. Colour denotes substance; invisible vapour appears only as explicitly schematic molecules.',background:palette.sky,foreground:'#214556',accent:palette.water,font:'sans-serif',fontSize:16,strokeWidth:0},
            layers:['atmosphere','distance','landscape','geology','vegetation','mechanism','detail','annotation'].map(id=>({id,label:id})),
            paths:[],gradients:[],objects:[],stages:[],
            accessibility:{summary:description,reducedMotion:'stage-final',stageDescriptions:[]}
        };
        this.serial=0;
    }
    gradient(id, colours, kind='linear') {
        this.scene.gradients.push({id,kind,stops:colours.map((c,i)=>({offset:i/(colours.length-1),colour:Array.isArray(c)?c[0]:c,opacity:Array.isArray(c)?c[1]:1}))});
        return id;
    }
    object(kind, geometry, x=0, y=0, fill='none', extra={}) {
        const {id=`shape${++this.serial}`,layer='landscape',parent,label='',gradient,initial={},...refs}=extra;
        const resolvedLayer=parent?this.scene.objects.find(o=>o.id===parent).layer:layer;
        this.scene.objects.push({id,kind,role:'draw',layer:resolvedLayer,label,...(parent?{parent}:{}),...(gradient?{gradient}:{}),...refs,geometry,initial:{x:round(x),y:round(y),fill,stroke:'none',strokeWidth:0,...initial}});
        return id;
    }
    group(id,x=0,y=0,extra={}){return this.object('group',{},x,y,'none',{id,...extra});}
    ellipse(x,y,rx,ry,fill,extra={}){return this.object('ellipse',{rx,ry},x,y,fill,extra);}
    circle(x,y,r,fill,extra={}){return this.object('circle',{radius:r},x,y,fill,extra);}
    rect(x,y,width,height,fill,extra={}){return this.object('rect',{width,height},x,y,fill,extra);}
    polygon(points,fill,extra={}){return this.object('polygon',{points},0,0,fill,extra);}
    path(d,fill,extra={}){
        const path=`curve${this.scene.paths.length+1}`;
        this.scene.paths.push({id:path,segments:commands(d)});
        return this.object('path',{path},0,0,fill,extra);
    }
    line(x,y,x2,y2,stroke,width=1,extra={}){
        return this.object('line',{x2:x2-x,y2:y2-y},x,y,'none',{...extra,initial:{stroke,strokeWidth:width,...extra.initial}});
    }
    text(x,y,label,extra={}){return this.object('text',{},x,y,'#547784',{layer:'annotation',label,...extra});}
    particles(id,points,radius,fill,extra={}){return this.object('particles',{points:points.map(p=>p.map(round)),radius},0,0,fill,{id,layer:'mechanism',...extra});}
    stage(title,description,durationMs=6500){
        const s={title,description,durationMs,actions:[]};this.scene.stages.push(s);
        this.scene.accessibility.stageDescriptions.push(description);return this.scene.stages.length-1;
    }
    action(stage,target,type,final,startMs=0,durationMs=2500,params={},easing='smooth'){
        this.scene.stages[stage].actions.push({target,startMs,durationMs,easing,operation:{type,params,final}});
    }
    reveal(stage,target,startMs=0,durationMs=1600){this.action(stage,target,'reveal',{visible:true,opacity:1},startMs,durationMs);}
    fade(stage,target,opacity,startMs=0,durationMs=1600){this.action(stage,target,'fade',{opacity},startMs,durationMs);}
    camera(stage,zoom,x=0,y=0,durationMs=3500){
        this.action(stage,'@camera','zoom',{zoom},0,durationMs);
        this.action(stage,'@camera','pan',{x,y},0,durationMs);
    }
    follow(stage,target,pathObject,startMs=0,durationMs=3000,from=0,to=1){
        const o=this.scene.objects.find(o=>o.id===pathObject);
        const p=this.scene.paths.find(p=>p.id===o.geometry.path);
        const end=to===1?p.segments.filter(s=>s.command!=='Z').at(-1).values.slice(-2):p.segments[0].values;
        this.action(stage,target,'follow_path',{x:end[0],y:end[1],rotation:0},startMs,durationMs,{path:p.id,from,to,orient:false});
    }
    finish(){
        const s=this.scene;
        if(s.objects.length>160||s.paths.length>32)throw new Error(`${s.title}: budget ${s.objects.length} objects/${s.paths.length} paths`);
        return s;
    }
}

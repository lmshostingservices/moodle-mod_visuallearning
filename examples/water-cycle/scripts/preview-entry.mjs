import {render} from '../../../amd/src/scene.js';
import {validateScene} from '../../../amd/src/scene_validator.js';
const host=document.getElementById('mount');
const nav=document.getElementById('chapters');
const stages=document.getElementById('stages');
const seek=document.getElementById('seek');
const toggle=document.getElementById('toggle');
let scenes=[],controller=null,current=0,card=1,playing=false,clock=0,last=0,fullReplay=false;
const names=['Sunlight','Evaporation','Transpiration','Rising air','Condensation','Rainfall','Groundwater','The whole cycle'];
function selectStage(index,time=0){
    card=index;clock=time;playing=false;fullReplay=false;toggle.textContent='Play stage';controller.seek(index,time);
    seek.max=scenes[current].stages[index-1].durationMs;seek.value=time;
    document.getElementById('time').textContent=(time/1000).toFixed(1)+'s';
    [...stages.children].forEach((button,i)=>button.classList.toggle('active',i+1===card));
}
function open(index){
    current=index;controller?.destroy();host.replaceChildren();
    controller=render(host,scenes[index],{controls:true,onReplay:()=>{
        selectStage(1);controller.replay();fullReplay=true;startClock();
    },onStageReplay:index=>{selectStage(index);controller.stage(index);startClock();}});
    if(!controller)throw new Error('Actual renderer rejected scene '+index);
    stages.replaceChildren();
    scenes[index].stages.forEach((s,i)=>{
        const button=document.createElement('button');
        const small=document.createElement('small');small.textContent=`STAGE 0${i+1}`;
        const strong=document.createElement('strong');strong.textContent=s.title;
        button.append(small,strong);button.onclick=()=>selectStage(i+1);stages.append(button);
    });
    [...nav.children].forEach((b,i)=>b.classList.toggle('active',i===index));
    document.getElementById('science').textContent=scenes[index].description;
    selectStage(1);
}
function startClock(){
    if(matchMedia('(prefers-reduced-motion: reduce)').matches){
        clock=Number(seek.max);seek.value=clock;playing=false;toggle.textContent='Play stage';return;
    }
    playing=true;last=performance.now();toggle.textContent='Pause';
}
toggle.onclick=()=>{
    if(playing){controller.pause(true);playing=false;toggle.textContent='Play stage';}
    else{if(clock>=Number(seek.max)){clock=0;controller.seek(card,0);}controller.pause(false);startClock();}
};
seek.oninput=()=>selectStage(card,Number(seek.value));
document.getElementById('end').onclick=()=>selectStage(card,Number(seek.max));
function tick(t){
    if(playing){
        clock+=Math.max(0,t-last);last=t;
        const max=Number(seek.max);
        if(clock>=max){
            const loop=scenes[current].stages[card-1].loopFromMs;
            if(loop!==undefined)clock=loop+(clock-loop)%(max-loop);
            else if(fullReplay&&card<3){
                card++;clock=0;seek.max=scenes[current].stages[card-1].durationMs;
                [...stages.children].forEach((button,i)=>button.classList.toggle('active',i+1===card));
            }else{clock=max;playing=false;toggle.textContent='Play stage';}
        }
        seek.value=clock;document.getElementById('time').textContent=(clock/1000).toFixed(1)+'s';
    }
    requestAnimationFrame(tick);
}
document.addEventListener('visibilitychange',()=>{last=performance.now();});
async function init(){
    const response=await fetch('../../manifest.json');
    if(!response.ok)throw new Error('Could not load benchmark manifest');
    const manifest=await response.json();
    scenes=await Promise.all(manifest.scenes.map(async item=>{
        const r=await fetch('../../'+item.file);if(!r.ok)throw new Error('Could not load '+item.file);
        const scene=await r.json();if(!validateScene(scene))throw new Error('Invalid scene '+item.file);return scene;
    }));
    names.forEach((name,i)=>{const button=document.createElement('button');button.textContent=`0${i+1} / ${name}`;button.onclick=()=>open(i);nav.append(button);});
    open(0);requestAnimationFrame(tick);
    window.showcase={open,selectStage,get controller(){return controller;},get scenes(){return scenes;}};
}
init().catch(error=>{
    host.replaceChildren();const box=document.createElement('div');box.className='error';box.textContent=error.message+' ';
    const retry=document.createElement('button');retry.textContent='Retry';retry.onclick=()=>location.reload();box.append(retry);host.append(box);
});

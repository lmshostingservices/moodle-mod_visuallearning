import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
const out=fileURLToPath(new URL('../',import.meta.url));
const root=path.resolve(out,'../..');
const bundle=await build({entryPoints:[path.join(root,'amd/src/scene_validator.js')],bundle:true,write:false,platform:'node',format:'esm'});
const {validateScene}=await import('data:text/javascript;base64,'+Buffer.from(bundle.outputFiles[0].text).toString('base64'));
const timeline=await build({entryPoints:[path.join(root,'amd/src/scene_timeline.js')],bundle:true,write:false,platform:'node',format:'esm'});
const {createEvaluator}=await import('data:text/javascript;base64,'+Buffer.from(timeline.outputFiles[0].text).toString('base64'));
const manifest=JSON.parse(await fs.readFile(path.join(out,'manifest.json'),'utf8'));
const results=[];
const visualState=value=>JSON.parse(JSON.stringify(value,(key,v)=>{
    if(key==='colourChanged'&&v===false)return undefined;
    return typeof v==='string'&&/^#[a-fA-F0-9]{6}$/.test(v)?v.toLowerCase():v;
}));
for(const {file} of manifest.scenes){
    const input=await fs.readFile(path.join(out,file),'utf8');
    const js=validateScene(JSON.parse(input));
    const scene=JSON.parse(input);
    const evaluate=createEvaluator(scene);
    assert.ok(evaluate,`${file}: evaluator must compile actual JSON`);
    for(let card=1;card<=3;card++){
        const at=evaluate(card,0),prior=evaluate(card===1?0:card-1,card===1?0:scene.stages[card-2].durationMs);
        assert.deepEqual(visualState(at.objects),visualState(prior.objects),`${file}: stage ${card} starts continuously`);
        assert.deepEqual(at.camera,prior.camera,`${file}: camera starts continuously`);
    }
    if(scene.stages[2].loopFromMs!==undefined){
        const a=evaluate(3,scene.stages[2].loopFromMs);
        const z=evaluate(3,scene.stages[2].durationMs);
        assert.deepEqual(a.camera,z.camera,'Finale camera must settle before loop');
        for(const o of scene.objects){
            const before=a.objects[o.id],after=z.objects[o.id];
            // Invisible transport cohorts reset under zero opacity. Visible dash flows
            // are periodic, so compare phase modulo their declared 22/cycles period.
            if(before.opacity===0&&after.opacity===0)continue;
            if(before.dashMode==='flow'&&after.dashMode==='flow'){
                const period=22/before.flowCycles;
                assert.ok(Math.abs((after.flowOffset-before.flowOffset)/period-Math.round((after.flowOffset-before.flowOffset)/period))<1e-7,'Flow phase must wrap exactly');
                const {flowOffset:unusedA,...restA}=before;
                const {flowOffset:unusedB,...restB}=after;
                assert.deepEqual(restA,restB);
            }else assert.deepEqual(before,after,`${o.id}: visible loop boundary pose must match`);
        }
    }
    const php=execFileSync('php',['-r',`define('MOODLE_INTERNAL',true);require $argv[1];echo \\mod_visuallearning\\local\\scene_validator::clean(file_get_contents($argv[2])) === null ? 'FAIL' : 'PASS';`,path.join(root,'classes/local/scene_validator.php'),path.join(out,file)],{encoding:'utf8'}).trim()==='PASS';
    results.push({file,javascript:js,php,stageContinuity:true,...(scene.version==='1.1'?{visibleLoopBoundary:true}: {})});
    console.log(`${file} JS:${js?'PASS':'FAIL'} PHP:${php?'PASS':'FAIL'}`);
}
await fs.writeFile(path.join(out,'generated-artifacts/svg-showcase/validation.json'),JSON.stringify(results,null,2)+'\n');
if(results.some(r=>!r.javascript||!r.php))process.exitCode=1;

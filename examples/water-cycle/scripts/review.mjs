/**
 * Actual browser inspection: bundled production render(), authored JSON, desktop/tablet.
 * Only a local, read-only benchmark HTTP server is used. No application workflow.
 */
import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {chromium} from 'playwright';
import {execFileSync} from 'node:child_process';
import assert from 'node:assert/strict';
const root=fileURLToPath(new URL('../',import.meta.url));
const out=path.join(root,'generated-artifacts/svg-showcase');
await fs.mkdir(path.join(out,'screenshots'),{recursive:true});
const mime={'.js':'text/javascript','.json':'application/json','.html':'text/html','.css':'text/css','.png':'image/png'};
const server=http.createServer(async(req,res)=>{
    try{
        const url=new URL(req.url,'http://localhost');
        const file=path.resolve(root,'.'+decodeURIComponent(url.pathname));
        if(!file.startsWith(root))throw new Error('Outside benchmark');
        const data=await fs.readFile(file.endsWith('/')?file+'index.html':file);
        res.writeHead(200,{'Content-Type':mime[path.extname(file)]||'text/plain'});res.end(data);
    }catch{res.writeHead(404);res.end('Benchmark file not found');}
});
await new Promise(resolve=>server.listen(5099,'0.0.0.0',resolve));
const browser=await chromium.launch({executablePath:execFileSync('which',['chromium'],{encoding:'utf8'}).trim(),headless:true,args:['--no-sandbox']});
const results=[];
try{
    for(const viewport of [{width:1440,height:1120},{width:820,height:1180}]){
        const page=await browser.newPage({viewport});
        const errors=[];
        page.on('pageerror',e=>errors.push(e.message));
        await page.goto('http://127.0.0.1:5099/generated-artifacts/svg-showcase/index.html');
        await page.waitForFunction(()=>window.showcase);
        for(let scene=0;scene<8;scene++){
            await page.evaluate(i=>window.showcase.open(i),scene);
            for(let stage=1;stage<=3;stage++){
                await page.evaluate(s=>window.showcase.selectStage(s,window.showcase.scenes[window.showcase.scenes.findIndex(x=>x.title===document.querySelector('svg title').textContent)].stages[s-1].durationMs),stage);
                assert.equal(await page.locator('#mount svg').count(),1);
                const box=await page.locator('#mount svg').boundingBox();
                assert.ok(box.width>600&&box.width<viewport.width);
                await page.locator('.scene-shell').screenshot({path:path.join(out,`screenshots/${viewport.width}-scene${scene+1}-stage${stage}.png`)});
            }
            if(scene===0)await page.screenshot({path:path.join(out,`showcase-${viewport.width}.png`),fullPage:true});
            if(scene===7){
                await page.evaluate(()=>window.showcase.selectStage(3,9000));
                await page.locator('.scene-shell').screenshot({path:path.join(out,`screenshots/${viewport.width}-scene8-loop-midpoint.png`)});
                await page.evaluate(()=>window.showcase.selectStage(3,11980));
                await page.evaluate(()=>window.showcase.controller.pause(false));
                await page.waitForTimeout(150);
                const opacity=await page.locator('[data-scene-object="loopRain"]').getAttribute('opacity');
                assert.ok(Number(opacity)<.35,'Actual stage clock must wrap to the beginning of the rain cohort');
                await page.waitForTimeout(900);
                assert.ok(Number(await page.locator('[data-scene-object="loopRain"]').getAttribute('opacity'))>.4,'Actual loop must continue animating after wrapping');
                await page.evaluate(()=>window.showcase.controller.pause(true));
            }
            const performance=await page.evaluate(()=>{
                const start=window.performance.now();
                for(let i=0;i<50;i++)window.showcase.controller.seek(2,i*100);
                return window.performance.now()-start;
            });
            await page.evaluate(()=>window.showcase.selectStage(2,2300));
            const paused=await page.locator('#mount svg').innerHTML();
            await page.waitForTimeout(80);
            assert.equal(await page.locator('#mount svg').innerHTML(),paused);
            results.push({viewport:viewport.width,scene:scene+1,fiftySeeksMs:Math.round(performance),pauseStable:true,stagesRendered:3});
        }
        // Exercise harness controls, real scene replay, seeking and reduced-motion final state.
        await page.evaluate(()=>window.showcase.open(0));
        await page.locator('#toggle').click();await page.waitForTimeout(180);
        await page.locator('#toggle').click();
        assert.ok(Number(await page.locator('#seek').inputValue())>0);
        await page.locator('#end').click();
        assert.equal(await page.locator('#seek').inputValue(),'6500');
        await page.getByRole('button',{name:'Replay complete animation',exact:true}).click();
        await page.waitForTimeout(60);
        await page.evaluate(()=>window.showcase.controller.pause(true));
        await page.emulateMedia({reducedMotion:'reduce'});
        await page.evaluate(()=>{window.showcase.open(7);window.showcase.controller.stage(3);});
        const reduced=await page.locator('#mount svg').innerHTML();
        await page.waitForTimeout(120);
        assert.equal(await page.locator('#mount svg').innerHTML(),reduced,'Reduced-motion finale must stay still');
        assert.deepEqual(errors,[]);
        await page.close();
    }
    // Contact sheet contains genuine rendered frames, not a substitute illustration.
    const page=await browser.newPage({viewport:{width:1600,height:1200}});
    const sheets=[];
    for(let i=1;i<=8;i++){
        const stage=({1:3,2:2,3:3,4:3,5:2,6:2,7:3,8:3})[i];
        const frame=i===8?'1440-scene8-loop-midpoint.png':`1440-scene${i}-stage${stage}.png`;
        sheets.push(`<figure><img src="http://127.0.0.1:5099/generated-artifacts/svg-showcase/screenshots/${frame}"><figcaption>0${i} · ${['Sunlight','Evaporation','Transpiration','Rising air','Condensation','Rainfall','Groundwater','Complete cycle'][i-1]}</figcaption></figure>`);
    }
    await page.setContent(`<body style="margin:0;padding:20px;background:#eef5f3;color:#214556;font:16px sans-serif"><h2>The living watershed · Actual engine frames</h2><div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">${sheets.join('')}</div><style>figure{margin:0}img{width:100%;display:block;border-radius:8px}figcaption{padding:7px}</style></body>`);
    await page.waitForLoadState('networkidle');
    await page.screenshot({path:path.join(out,'contact-sheet.png'),fullPage:true});
    await fs.writeFile(path.join(out,'browser-review.json'),JSON.stringify({provenance:'Authored benchmark; not generated-provider acceptance',errors:[],captures:50,actualLoopPlayback:true,reducedMotion:true,results},null,2)+'\n');
    console.log('PASS: 50 real-renderer desktop/tablet frames, actual loop wrap, reduced motion, pause, seek, replay, no page errors.');
}finally{await browser.close();if(!process.argv.includes('--serve'))server.close();}
if(process.argv.includes('--serve'))console.log('Read-only benchmark preview: http://127.0.0.1:5099/generated-artifacts/svg-showcase/index.html');

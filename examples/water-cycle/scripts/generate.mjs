/**
 * Authored visual benchmark, NOT model output or evidence of provider acceptance.
 * Run from any directory: node .../water-cycle/scripts/generate.mjs
 */
import fs from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import path from 'node:path';
import {Illustration, rng} from './lib/illustration.mjs';
const OUT=fileURLToPath(new URL('../',import.meta.url));
const P={sun:'#FFCA45',ocean:'#1265B2',water:'#16B9C5',vapour:'#8DE7F0',cloud:'#F8FCFF',shadow:'#A9C3D5',forest:'#38A86A',soil:'#A67C52',rock:'#64748B',sky:'#EAF7FF'};
const hidden={initial:{opacity:0}};
const stroke=(colour,width=2)=>({initial:{stroke:colour,strokeWidth:width}});

function tree(b,x,y,size=1,id='tree',layer='vegetation'){
    const parent=b.group(id,x,y,{layer,initial:{scaleX:size,scaleY:size}});
    b.ellipse(0,5,43,9,'#214F50',{parent,initial:{opacity:.13}});
    b.polygon([[-8,0],[-4,-98],[3,-104],[10,0]],'#6D6453',{parent});
    b.line(0,-43,-30,-85,'#6D6453',4,{parent});
    b.path('M -52 -92 C -66 -108 -52 -130 -38 -137 C -43 -160 -26 -183 -4 -185 C 16 -191 41 -173 39 -151 C 61 -145 67 -128 56 -111 C 61 -87 37 -73 12 -75 C -6 -63 -36 -70 -52 -92 Z',P.forest,{parent,gradient:'gLeaf'});
    b.ellipse(-23,-108,27,29,'#237F58',{parent,initial:{opacity:.32}});
    b.ellipse(-12,-154,22,24,'#75C483',{parent,initial:{opacity:.25}});
    return parent;
}
function cloud(b,id,x,y,size=1,opacity=1){
    const parent=b.group(id,x,y,{layer:'distance',initial:{scaleX:size,scaleY:size,opacity}});
    b.path('M -103 11 C -119 -12 -88 -35 -62 -29 C -65 -67 -12 -83 15 -55 C 42 -69 74 -50 74 -29 C 117 -34 135 12 94 23 C 39 38 -54 37 -103 11 Z',P.cloud,{parent,gradient:'gCloud'});
    b.ellipse(3,19,91,13,P.shadow,{parent,gradient:'gCloudShade',initial:{opacity:.36}});
    b.ellipse(-19,-33,33,27,P.cloud,{parent,initial:{opacity:.38}});
    b.ellipse(49,-17,32,21,P.cloud,{parent,initial:{opacity:.27}});
    return parent;
}
function world(title,description,{ground=true,largeTree=false,clouds=true}={}){
    const b=new Illustration(title,description,P);
    b.gradient('gSky',['#D5EEFB',P.sky,'#F5FCFF']);
    b.gradient('gOcean',[P.ocean,'#159DC2',P.water]);
    b.gradient('gWater',['#0D86B6',P.water,'#8AE2DB']);
    b.gradient('gLeaf',['#237950',P.forest,'#61B977']);
    b.gradient('gCloud',[P.shadow,'#EAF4F9',P.cloud]);
    b.gradient('gCloudShade',['#92B4CC','#DDEEF6',P.cloud]);
    b.gradient('gEarth',['#876748',P.soil,'#C6A578']);
    b.gradient('gRock',['#52677E',P.rock,'#8F9DAB']);
    b.gradient('gGold',[[P.sun,.03],[P.sun,.24],[P.sun,.02]],'radial');
    b.gradient('gSun',['#FFF0AC',P.sun,'#EDAE39'],'radial');
    b.gradient('gGlass',['#F8FCFF','#E1F3F9','#C6EAF2']);
    b.rect(-250,-200,1700,1100,P.sky,{layer:'atmosphere',gradient:'gSky'});
    b.circle(175,133,110,P.sun,{id:'sunHalo',layer:'atmosphere',gradient:'gGold'});
    b.circle(175,133,53,P.sun,{id:'sunDisc',layer:'atmosphere',gradient:'gSun'});
    b.circle(160,118,29,'#FFEBA1',{layer:'atmosphere',initial:{opacity:.25}});
    b.path('M 360 407 L 533 203 L 603 281 L 704 138 L 876 336 L 976 221 L 1200 414 Z','#C0D9E4',{layer:'distance'});
    b.path('M 438 413 L 610 244 L 674 316 L 774 205 L 945 409 Z','#8FB3C5',{layer:'distance'});
    b.path('M 774 205 L 749 328 L 853 409 L 945 409 Z','#6F9BB1',{layer:'distance'});
    b.path('M 704 138 L 651 207 L 685 195 L 705 213 L 721 198 L 753 211 Z','#F0F8FB',{layer:'distance'});
    b.path('M 610 244 L 580 274 L 606 266 L 620 278 L 634 273 Z','#DDEDF3',{layer:'distance'});
    b.polygon([[533,203],[520,253],[546,245],[560,279],[579,256]],'#DDEDF3',{layer:'distance'});
    b.polygon([[704,213],[691,290],[651,338],[679,264]],'#A7C9D7',{layer:'distance'});
    b.polygon([[774,205],[786,271],[812,299],[804,254]],'#AECBD8',{layer:'distance'});
    b.polygon([[610,266],[592,317],[567,341],[598,286]],'#B7D3DD',{layer:'distance'});
    b.path('M 0 422 Q 146 401 333 419 Q 466 436 583 432 L 635 554 L 0 572 Z',P.ocean,{gradient:'gOcean'});
    b.path('M 456 442 Q 568 377 711 396 Q 823 356 937 379 Q 1075 335 1200 364 L 1200 555 L 410 555 Z','#65A882',{gradient:'gLeaf'});
    b.path('M 579 434 Q 748 411 877 424 Q 1042 391 1200 423 L 1200 554 L 408 554 Z','#88BC8C');
    b.path('M 670 409 Q 670 394 678 397 Q 680 376 691 391 Q 698 374 706 391 Q 716 370 723 391 Q 730 378 736 395 Q 743 370 751 390 Q 759 366 768 390 Q 778 373 784 391 Q 795 375 802 396 Q 810 377 818 393 Q 828 369 837 389 Q 847 373 854 394 L 864 413 Q 767 421 670 409 Z','#3B936B');
    b.path('M 1042 401 Q 1044 374 1055 387 Q 1065 363 1074 381 Q 1083 359 1090 378 Q 1099 353 1109 374 Q 1118 352 1128 370 Q 1140 343 1147 366 Q 1158 345 1169 363 Q 1183 342 1190 361 L 1200 396 Z','#398D68');
    b.path('M 856 407 C 792 444 917 447 845 472 C 790 491 739 470 683 503 C 626 538 533 524 428 555 L 379 555 C 525 508 605 517 650 487 C 718 446 795 479 817 457 C 834 439 784 433 833 405 Z',P.water,{id:'river',gradient:'gWater'});
    b.ellipse(692,502,86,22,P.water,{gradient:'gWater'});
    b.path('M 448 443 Q 391 462 432 481 Q 463 496 407 517 Q 375 532 399 549','none',{...stroke('#D1EEE3',4)});
    b.path('M 0 467 C 99 456 195 476 292 460 Q 347 452 389 463','none',{id:'waveNear',...stroke('#80DBE7',2),initial:{stroke:'#80DBE7',strokeWidth:2}});
    b.path('M 41 498 Q 142 480 238 494 Q 295 499 338 489','none',{id:'waveFar',...stroke('#84D9E3',1.4),initial:{stroke:'#84D9E3',strokeWidth:1.4,opacity:.65}});
    b.line(74,434,189,434,'#B9ECED',1.5);
    b.line(240,447,300,447,'#B9ECED',1.2);
    b.line(641,500,701,497,'#B9ECED',1.4);
    if(ground){
        b.path('M 0 552 Q 190 558 389 550 Q 578 541 760 546 Q 974 523 1200 536 L 1200 720 L 0 720 Z',P.soil,{id:'earth',layer:'geology',gradient:'gEarth'});
        b.path('M 0 590 Q 238 613 435 585 Q 639 573 839 591 Q 1048 567 1200 577 L 1200 720 L 0 720 Z','#C2A37E',{layer:'geology'});
        b.path('M 0 652 Q 211 632 438 649 Q 624 632 801 650 Q 1000 619 1200 630 L 1200 720 L 0 720 Z',P.rock,{layer:'geology',gradient:'gRock'});
        b.path('M 0 661 Q 261 645 438 661 Q 718 644 1200 647 L 1200 687 Q 819 675 492 693 Q 218 670 0 692 Z',P.ocean,{id:'aquifer',layer:'geology',gradient:'gWater',initial:{opacity:.62}});
        const random=rng(42);
        b.particles('soilGrains',Array.from({length:60},()=>[random()*1200,572+random()*64]),2.1,'#927451',{layer:'geology',initial:{opacity:.45}});
        b.particles('rockPores',Array.from({length:44},()=>[random()*1200,650+random()*64]),3.2,'#D1DBDF',{layer:'geology',initial:{opacity:.27}});
        for(let i=0;i<8;i++)b.ellipse(65+i*151,610+(i%3)*9,16+(i%3)*5,6+(i%2)*3,'#8E8C80',{layer:'geology',initial:{rotation:(i%3-1)*18,opacity:.65}});
    }
    [[563,431,.32],[607,429,.4],[1056,413,.52],[1130,408,.65]].forEach(([x,y,s],i)=>tree(b,x,y,s,`grove${i}`));
    tree(b,largeTree?929:969,largeTree?548:483,largeTree?1.52:.79,'heroTree');
    if(clouds){cloud(b,'cloudWest',410,186,.72,.85);cloud(b,'cloudEast',949,161,.94,.97);}
    // Reused benthic/surface texture; no bubbles, smoke or vapour painted into the world.
    b.text(35,690,'THE LIVING WATERSHED',{initial:{fill:'#D6E9EB',scaleX:.68,scaleY:.68}});
    return b;
}
function rays(b){
    const parent=b.group('energy',0,0,{layer:'mechanism',...hidden});
    for(const [x,y,w] of [[218,431,9],[290,450,12],[367,440,8]]){
        b.polygon([[160,162],[175,159],[x+w,y],[x-w,y]],P.sun,{parent,gradient:'gGold'});
        b.line(175,171,x,y,P.sun,2,{parent,initial:{stroke:P.sun,strokeWidth:2,opacity:.64,drawProgress:0},id:`ray${x}`});
    }
    b.ellipse(280,453,127,17,P.sun,{id:'warmSurface',parent,gradient:'gGold'});
    return parent;
}
function waveMotion(b,stage){
    b.action(stage,'waveNear','move',{x:10,y:1},0,6000);
    b.action(stage,'waveFar','move',{x:-8,y:0},0,6000);
}
function molecule(b,id,x,y,size=1,parent){
    const group=b.group(id,x,y,{layer:'mechanism',...(parent?{parent}:{}),initial:{scaleX:size,scaleY:size}});
    b.line(-7,-4,-16,-15,'#61B7CD',3,{parent:group});
    b.line(7,-4,16,-15,'#61B7CD',3,{parent:group});
    b.circle(0,0,10,P.ocean,{parent:group,gradient:'gWater'});
    b.circle(-16,-15,5.1,P.cloud,{parent:group,...stroke('#83C4D5',1.2)});
    b.circle(16,-15,5.1,P.cloud,{parent:group,...stroke('#83C4D5',1.2)});
    return group;
}
function lens(b,id,x,y,rx,ry,label){
    const parent=b.group(id,x,y,{layer:'detail',...hidden});
    b.ellipse(5,12,rx+6,ry+4,'#497E8A',{parent,initial:{opacity:.12}});
    b.ellipse(0,0,rx,ry,P.cloud,{parent,...stroke('#9AC6D5',1.5),gradient:'gGlass'});
    b.text(-rx+28,-ry+36,label,{parent,initial:{fill:'#426B7D',scaleX:.78,scaleY:.78}});
    return parent;
}
function stages(b,items){items.forEach(([title,description,duration])=>b.stage(title,description,duration||6500));}
const scenes=[];

// 01 · Energy visibly arrives at the water; the outcome is not decorative sun pulsing.
{
    const b=world('The Sun powers the water cycle','Sunlight transfers energy to the ocean surface in a layered coastal watershed. Golden bands represent incoming solar energy, not water.');
    stages(b,[['Meet the watershed','The same ocean, mountains, forest and river will guide our journey. Sunlight is the main energy source.'],['Sunlight reaches water','Golden light reaches the ocean and land. Water absorbs some of this incoming energy.'],['A warmed surface','A moving golden reflection marks the warmed surface. Energy can support evaporation without boiling the ocean.']]);
    rays(b);b.camera(0,1.03,-8,-5);b.action(0,'sunHalo','scale',{scaleX:1.14,scaleY:1.14},0,4200);
    b.reveal(1,'energy',0,1000);
    [218,290,367].forEach((x,i)=>b.action(1,`ray${x}`,'draw_path',{drawProgress:1},i*450,3400));
    b.action(2,'warmSurface','move',{x:95,y:0},0,5200);
    b.camera(2,1.12,-75,34);waveMotion(b,0);waveMotion(b,1);waveMotion(b,2);
    scenes.push(['01-sun',b.finish()]);
}
// 02 · A magnification remains anchored to the ocean. H2O symbols, never steam.
{
    const b=world('The invisible journey of evaporation','A scientific magnification shows selected water molecules escaping the liquid surface. Water vapour is invisible; enlarged molecular symbols are a model.');
    stages(b,[['Look closely at liquid water','The magnified surface reveals crowded water molecules. These enlarged symbols are a model, not visible bubbles.'],['Some molecules escape','Selected surface molecules have enough energy to leave the liquid. Evaporation happens at the surface, without boiling.'],['Invisible vapour mixes with air','Escaped molecules move farther apart in the air. The symbols fade because real water vapour is invisible.']]);
    const panel=lens(b,'molecularView',655,292,370,218,'MAGNIFIED SURFACE · MOLECULES NOT TO SCALE');
    Object.assign(b.scene.objects.find(o=>o.id===panel).initial,{x:277,y:439,scaleX:.045,scaleY:.045});
    b.path('M -345 52 Q -170 37 0 53 Q 190 66 345 48 L 300 133 Q 0 277 -300 133 Z',P.ocean,{parent:panel,gradient:'gWater'});
    b.path('M -345 52 Q -170 37 0 53 Q 190 66 345 48','none',{parent:panel,...stroke('#8DDCE4',3)});
    const positions=[[-240,99],[-120,137],[14,113],[139,137],[255,94],[-24,181]];
    positions.forEach(([x,y],i)=>molecule(b,`liquid${i}`,x,y,1.12,panel));
    const escape=[[-155,55],[-20,52],[139,57]];
    escape.forEach(([x,y],i)=>molecule(b,`escape${i}`,x,y,1.18,panel));
    b.line(285,432,380,401,'#8ABCC9',1.2,{layer:'mechanism'});
    b.circle(277,439,13,'none',{layer:'mechanism',...stroke('#B4E5E8',2)});
    b.reveal(0,panel,300,1800);
    b.action(0,panel,'move',{x:655,y:292},300,3300);
    b.action(0,panel,'scale',{scaleX:1,scaleY:1},300,3300);
    b.camera(0,1.05,0,-18);
    positions.forEach(([x,y],i)=>b.action(0,`liquid${i}`,'move',{x:x+(i%2?6:-6),y:y-3},2200,3200));
    escape.forEach(([x,y],i)=>b.action(1,`escape${i}`,'move',{x:x+(i-1)*36,y:-55-i*23},300+i*400,4000));
    escape.forEach(([x],i)=>{b.action(2,`escape${i}`,'move',{x:x+(i-1)*93,y:-120-i*15},0,4000);b.fade(2,`escape${i}`,.14,500,4200);});
    b.fade(2,panel,.16,3800,2500);b.camera(2,1,0,0,6000);
    scenes.push(['02-evaporation',b.finish()]);
}
// 03 · Connected soil → root → xylem → leaf → stoma journey.
{
    const b=world('Water’s secret journey through a tree','A connected cutaway traces water from moist soil into roots, upwards through xylem and out of a leaf as invisible water vapour.',{largeTree:true,clouds:false});
    stages(b,[['Water enters the roots','Roots take up water from moist soil. The underground network connects to the trunk.'],['Up through the xylem','Water travels through connected water-conducting tissue in the trunk and branches towards the leaves.'],['Out through a leaf','Within a magnified leaf, liquid water evaporates. Invisible vapour diffuses out through a stoma. Symbols show what our eyes cannot see.'],]);
    const root=b.group('rootNetwork',929,548,{layer:'mechanism',...hidden});
    const roots=b.path('M 0 0 C -12 25 -28 39 -79 52','none',{parent:root,...stroke('#715D48',6)});
    b.path('M 1 0 C 12 33 54 27 84 47','none',{parent:root,...stroke('#715D48',5)});
    b.path('M -24 29 Q -27 49 -39 65','none',{parent:root,...stroke('#715D48',3)});
    b.path('M 43 31 Q 45 57 69 62','none',{parent:root,...stroke('#715D48',3)});
    const xylem=b.path('M 850 600 C 913 582 926 570 929 544 L 929 405 Q 928 377 953 359','none',{id:'xylem',layer:'mechanism',...stroke(P.water,5),initial:{stroke:P.water,strokeWidth:5,drawProgress:0}});
    const intake=b.particles('rootWater',[[839,604],[857,609],[873,594]],4,P.water);
    const drop=b.circle(850,600,5,P.vapour,{id:'xylemDrop',layer:'mechanism',initial:{opacity:0}});
    const leaf=lens(b,'leafDetail',476,252,290,178,'LEAF CROSS-SECTION · ENLARGED');
    Object.assign(b.scene.objects.find(o=>o.id===leaf).initial,{x:953,y:359,scaleX:.04,scaleY:.04});
    b.path('M -236 10 C -110 -116 121 -79 234 13 C 121 111 -119 128 -236 10 Z','#78C688',{parent:leaf,gradient:'gLeaf'});
    b.path('M -215 10 Q 0 27 209 13','none',{parent:leaf,...stroke('#B7E0A2',5)});
    b.line(-140,-34,-93,73,'#B7E0A2',2,{parent:leaf});
    b.line(-40,-51,9,78,'#B7E0A2',2,{parent:leaf});
    b.line(71,-43,121,68,'#B7E0A2',2,{parent:leaf});
    b.ellipse(29,85,26,13,'#D5ECAD',{parent:leaf});
    b.ellipse(64,85,26,13,'#D5ECAD',{parent:leaf});
    b.ellipse(46,84,5,13,'#245E50',{parent:leaf});
    // A peeled-away lower epidermis: connected air spaces above paired guard cells.
    for(let i=0;i<9;i++){
        const x=-176+i*43;
        b.ellipse(x,44,16,11,'#A5D491',{parent:leaf,initial:{opacity:.75}});
        if(i!==5)b.rect(x-18,69,34,12,'#BBDFA2',{parent:leaf});
    }
    b.line(953,359,738,311,'#7BA7A6',1,{layer:'detail',initial:{stroke:'#7BA7A6',strokeWidth:1,opacity:.4}});
    b.text(-201,136,'STOMA: A TINY OPENING',{parent:leaf,initial:{fill:'#426B7D',scaleX:.7,scaleY:.7}});
    molecule(b,'leafVapour',46,52,.66,leaf);
    b.reveal(0,root);b.action(0,intake,'merge_particles',{point:[918,559]},800,4200);
    b.action(1,xylem,'draw_path',{drawProgress:1},0,4000);b.reveal(1,drop,0,300);b.follow(1,drop,xylem,0,5000);b.camera(1,1.08,34,-36);
    b.reveal(2,leaf,0,1800);b.action(2,leaf,'move',{x:476,y:252},0,2400);b.action(2,leaf,'scale',{scaleX:1,scaleY:1},0,2400);b.action(2,'leafVapour','move',{x:46,y:84},2400,1200);b.action(2,'leafVapour','move',{x:46,y:158},3600,2400);b.fade(2,'leafVapour',.15,3600,2400);b.camera(2,1.08,-15,-24);
    scenes.push(['03-transpiration',b.finish()]);
}
// 04 · One and the same air parcel expands while rising, then cools. No cloud.
{
    const b=world('Rising air expands and cools','A single schematic parcel of moist air rises into lower surrounding pressure, expands and cools. Cooling alone does not guarantee a cloud.',{clouds:false});
    stages(b,[['Warm, moist air near the surface','A translucent amber boundary identifies a parcel of air, not a giant water droplet. Its water vapour is invisible.'],['Lower pressure, more room','The same parcel rises. Lower surrounding pressure lets it expand. The expansion is linked to cooling.'],['Expanded air is cooler','The expanded parcel is now cooler and higher. No cloud is automatically created: condensation needs suitable conditions.']]);
    b.gradient('gParcel',['#FFE6A0','#FFCA45','#F8E8BD'],'radial');
    const parcel=b.circle(348,393,48,P.sun,{id:'airParcel',layer:'mechanism',gradient:'gParcel',initial:{opacity:.66,stroke:'#DAAA4C',strokeWidth:1.3}});
    const marks=b.group('parcelMarks',348,393,{layer:'detail'});
    [[-19,-13],[12,-24],[24,17],[-19,23]].forEach(([x,y],i)=>molecule(b,`parcelMol${i}`,x,y,.22,marks));
    b.text(57,324,'WARMER NEAR THE SURFACE',{initial:{fill:'#A98A55',scaleX:.7,scaleY:.7}});
    b.text(534,104,'LOWER PRESSURE · COOLER',{initial:{fill:'#658B9F',scaleX:.7,scaleY:.7}});
    b.action(0,parcel,'move',{x:348,y:352},1500,3500);b.action(0,marks,'move',{x:348,y:352},1500,3500);
    for(const id of [parcel,marks]){b.action(1,id,'move',{x:426,y:192},0,6000);b.action(1,id,'scale',{scaleX:1.75,scaleY:1.75},0,6000);}
    b.action(1,parcel,'change_colour',{fill:'#BBDDE8'},0,6000);
    b.action(2,parcel,'change_colour',{fill:P.vapour},0,4000);
    for(const id of [parcel,marks])b.action(2,id,'move',{x:471,y:155},0,4200);
    b.camera(1,1.04,0,-30);b.camera(2,1.06,0,-44);
    scenes.push(['04-rising-air',b.finish()]);
}
// 05 · Vapour gathers on actual nuclei. Cloud reveal occurs only after liquid droplets.
{
    const b=world('The birth of a cloud','Invisible vapour condenses onto tiny airborne nuclei. The enlarged liquid droplets resolve into a cloud of suspended droplets.',{clouds:false});
    stages(b,[['Invisible ingredients','The magnified air contains tiny airborne nuclei and widely separated schematic water molecules. Real vapour is invisible.'],['Liquid grows around a nucleus','In sufficiently cool, humid air, water condenses onto airborne nuclei. Small liquid droplets form around them.'],['A cloud of droplets','Zooming back reveals many suspended droplets together: a cloud. Clouds are not visible water vapour.']]);
    cloud(b,'formingCloud',793,202,1.5,0);
    const view=lens(b,'condensationView',570,281,340,210,'MAGNIFIED MOIST AIR · SYMBOLIC SCALE');
    [[-145,-5],[30,-42],[177,50]].forEach(([x,y],i)=>{
        b.circle(x,y,10,P.water,{id:`droplet${i}`,parent:view,gradient:'gWater',initial:{opacity:0}});
        b.circle(x,y,5,'#817C76',{id:`nucleus${i}`,parent:view});
        b.circle(x-3,y-4,3,'#DDF9F7',{id:`glint${i}`,parent:view,initial:{opacity:0}});
        [[-64,-60],[62,-42],[-43,65]].forEach(([dx,dy],j)=>molecule(b,`condMol${i}_${j}`,x+dx,y+dy,.7,view));
    });
    b.reveal(0,view,300,2200);b.camera(0,1.04,-15,-20);
    [[-145,-5],[30,-42],[177,50]].forEach(([x,y],i)=>{
        for(let j=0;j<3;j++){b.action(1,`condMol${i}_${j}`,'move',{x,y},j*450,2400);b.fade(1,`condMol${i}_${j}`,0,1800+j*450,1000);}
        b.reveal(1,`droplet${i}`,1600,1500);b.action(1,`droplet${i}`,'scale',{scaleX:2.7,scaleY:2.7},1800,3500);b.reveal(1,`glint${i}`,3000,1000);
    });
    b.action(2,view,'move',{x:793,y:202},0,3500);b.action(2,view,'scale',{scaleX:.13,scaleY:.13},0,3500);
    b.fade(2,view,0,0,3500);b.reveal(2,'formingCloud',1300,3500);b.camera(2,1,0,0,5500);
    scenes.push(['05-condensation',b.finish()]);
}
// 06 · Coalescence before gravity-driven rain. Small drops are near-spherical.
{
    const b=world('When clouds release rain','A cloud magnification shows small liquid droplets colliding and coalescing. Larger drops fall under gravity across the watershed.');
    stages(b,[['Inside a cloud','Clouds contain many tiny suspended droplets. This magnified window shows liquid drops, not vapour.'],['Collisions make larger drops','Small droplets meet and combine. Some grow large enough to fall more readily through the surrounding air.'],['Rain returns water','Gravity pulls the larger drops towards the mountains, forest, river and lake. Motion accelerates downward; drops are shown round, not tear-shaped.']]);
    const view=lens(b,'rainDetail',598,255,293,178,'INSIDE THE CLOUD · ENLARGED DROPLETS');
    Object.assign(b.scene.objects.find(o=>o.id===view).initial,{x:949,y:161,scaleX:.04,scaleY:.04});
    const centres=[[-124,-5],[27,13],[157,-24]];
    centres.forEach(([x,y],i)=>{
        b.circle(x,y,10,P.water,{id:`rainSeed${i}`,parent:view,gradient:'gWater'});
        b.circle(x-5,y-5,3,'#DBF8F9',{parent:view});
        [[-31,-38],[36,-26],[-24,39]].forEach(([dx,dy],j)=>b.circle(x+dx,y+dy,8+j,P.water,{id:`smallDrop${i}_${j}`,parent:view,gradient:'gWater'}));
    });
    b.reveal(0,view,200,1800);b.action(0,view,'move',{x:598,y:255},200,3000);b.action(0,view,'scale',{scaleX:1,scaleY:1},200,3000);b.camera(0,1.04,5,-22);
    centres.forEach(([x,y],i)=>{
        for(let j=0;j<3;j++){b.action(1,`smallDrop${i}_${j}`,'move',{x,y},j*380,2200);b.fade(1,`smallDrop${i}_${j}`,0,2400+j*380,500);}
        // Approximate volume conservation: 10³ + 8³ + 9³ + 10³ = 3241.
        b.action(1,`rainSeed${i}`,'scale',{scaleX:Math.cbrt(3.241),scaleY:Math.cbrt(3.241)},1600,2800);
    });
    const rain=b.group('fallingRain',0,0,{layer:'mechanism',...hidden});
    for(let i=0;i<17;i++){
        const x=795+(i%8)*42;const y=181+Math.floor(i/8)*24;
        b.ellipse(x,y,1.8,4.2,P.ocean,{id:`rain${i}`,parent:rain,initial:{opacity:.7}});
        b.action(2,`rain${i}`,'move',{x:x-14,y:408+(i%4)*24},1400+(i%4)*220,3200,{},'ease-in');
        b.fade(2,`rain${i}`,0,5100,700);
    }
    b.action(2,view,'move',{x:949,y:161},0,2500);b.action(2,view,'scale',{scaleX:.12,scaleY:.12},0,2500);
    b.fade(2,view,0,0,2500);b.reveal(2,rain,1000,800);b.camera(2,1,0,0,4000);
    scenes.push(['06-precipitation',b.finish()]);
}
// 07 · Different time scales, one permeable cutaway. Groundwater is not a pipe.
{
    const b=world('The hidden world beneath our feet','A detailed soil and rock cutaway distinguishes fast downhill surface runoff, infiltration into pore spaces and much slower groundwater movement through permeable material.',{clouds:true});
    stages(b,[['Rain reaches the hillside','Rain reaches the sloping hillside. The cutaway exposes soil grains, pores, permeable rock and a groundwater zone.'],['Follow the downhill route','Some water runs across the surface into the stream and lake, following the downhill slope rather than climbing terrain.'],['Another route through the ground','Other water infiltrates soil and percolates through pore spaces. In the saturated zone, groundwater moves far more slowly towards the coast.'],]);
    const runoff=b.path('M 1135 435 Q 990 452 888 468 Q 770 469 700 500 Q 594 530 433 548','none',{id:'runoffRoute',layer:'mechanism',...stroke('#C3F4ED',3),initial:{stroke:'#C3F4ED',strokeWidth:3,drawProgress:0}});
    const infiltrate=b.path('M 1030 462 C 1038 502 1001 533 1011 570 C 1028 600 990 615 995 653','none',{id:'infiltrationRoute',layer:'mechanism',...stroke(P.vapour,3),initial:{stroke:P.vapour,strokeWidth:3,drawProgress:0}});
    const groundwater=b.path('M 995 665 C 909 673 794 662 691 677 C 564 688 417 676 301 683','none',{id:'groundRoute',layer:'mechanism',...stroke('#A5E3E8',2),initial:{stroke:'#A5E3E8',strokeWidth:2,drawProgress:0}});
    b.text(43,577,'SOIL & PORE SPACES',{initial:{fill:'#614D38',scaleX:.65,scaleY:.65}});
    b.text(43,638,'PERMEABLE MATERIAL',{initial:{fill:'#614D38',scaleX:.65,scaleY:.65}});
    b.text(768,704,'SATURATED GROUND · SLOW FLOW',{initial:{fill:'#D3F0EF',scaleX:.65,scaleY:.65}});
    const rain=b.particles('slopeRain',[[1070,325],[1110,300],[1150,334],[1030,311],[990,336]],2.5,P.ocean);
    b.action(0,rain,'move',{x:-15,y:112},300,3900,{},'ease-in');b.fade(0,rain,0,4400,900);
    b.action(1,runoff,'draw_path',{drawProgress:1},0,4000);
    b.circle(1135,435,5,P.vapour,{id:'runoffDrop',layer:'mechanism'});
    b.follow(1,'runoffDrop',runoff,0,4000);
    b.camera(2,1.07,25,55);
    b.action(2,infiltrate,'draw_path',{drawProgress:1},0,4500);
    b.action(2,groundwater,'draw_path',{drawProgress:.24},3500,3000);
    b.circle(1030,462,4,P.vapour,{id:'poreWater',layer:'mechanism',initial:{opacity:0}});
    b.reveal(2,'poreWater',0,400);b.follow(2,'poreWater',infiltrate,0,4500);
    b.action(2,'poreWater','move',{x:954,y:668},4500,2000);
    scenes.push(['07-groundwater',b.finish()]);
}
// 08 · The optional v1.1 finale uses an actual bounded stage-three loop contract.
{
    const b=world('One connected, living water cycle','The same watershed brings energy, evaporation, transpiration, rising air, condensation, rain, runoff, infiltration and groundwater together. Routes branch and real-world speeds differ.');
    b.scene.objects.find(o=>o.id==='cloudWest').initial.opacity=0;
    stages(b,[['Energy starts many journeys','Sunlight reaches the ocean. Schematic water molecules leave the surface and the tree; real vapour is invisible.'],['The atmosphere connects them','A moist air parcel rises, expands and cools. Under suitable conditions droplets condense into a cloud, then some grow and fall as rain.'],['Many routes, one watershed','Surface water flows downhill; other water enters soil and groundwater. Processes operate together, but not every molecule takes the same route or travels at the same speed.',12000]]);
    rays(b);b.reveal(0,'energy');[218,290,367].forEach((x,i)=>b.action(0,`ray${x}`,'draw_path',{drawProgress:1},i*300,3500));
    b.camera(0,1.04,-12,-8);b.camera(1,1.08,20,-20);
    molecule(b,'oceanSymbol',296,443,.37);molecule(b,'treeSymbol',970,355,.32);
    b.action(0,'oceanSymbol','move',{x:324,y:315},1500,4200);b.action(0,'treeSymbol','move',{x:956,y:262},1900,3900);
    b.fade(0,'oceanSymbol',.3,2800,2800);b.fade(0,'treeSymbol',.3,2800,2800);
    const parcel=b.circle(346,325,30,P.sun,{id:'finalParcel',layer:'mechanism',initial:{opacity:0}});
    b.reveal(1,parcel,0,700);b.action(1,parcel,'move',{x:527,y:190},0,3000);b.action(1,parcel,'scale',{scaleX:1.6,scaleY:1.6},0,3000);b.action(1,parcel,'change_colour',{fill:P.vapour},0,3000);b.fade(1,parcel,0,3300,1200);
    b.action(1,'cloudWest','move',{x:508,y:186},0,5000);
    b.reveal(1,'cloudWest',2500,2800);
    const rain=b.particles('finalRain',Array.from({length:18},(_,i)=>[821+(i%9)*31,179+Math.floor(i/9)*27]),2.1,P.ocean,{...hidden});
    b.reveal(1,rain,3100,800);b.action(1,rain,'move',{x:-14,y:220},3300,3000,{},'ease-in');
    const surface=b.path('M 1124 435 Q 938 464 853 474 Q 755 476 690 501 Q 567 532 430 549','none',{id:'finalRunoff',layer:'mechanism',...stroke('#D7F7E7',2),initial:{stroke:'#D7F7E7',strokeWidth:2,drawProgress:0}});
    const into=b.path('M 1009 474 Q 992 520 1005 557 Q 1021 606 984 662','none',{id:'finalInfiltration',layer:'mechanism',...stroke(P.vapour,2),initial:{stroke:P.vapour,strokeWidth:2,drawProgress:0}});
    const under=b.path('M 979 668 Q 793 682 637 680 Q 460 683 285 681','none',{id:'finalGroundwater',layer:'mechanism',...stroke('#93DFE6',2),initial:{stroke:'#93DFE6',strokeWidth:2,drawProgress:0}});
    b.action(2,surface,'draw_path',{drawProgress:1},0,3800);b.action(2,into,'draw_path',{drawProgress:1},700,4500);b.action(2,under,'draw_path',{drawProgress:.2},3500,2400);
    b.action(2,'oceanSymbol','move',{x:354,y:226},0,4800);b.action(2,'treeSymbol','move',{x:936,y:222},0,4800);
    b.fade(2,'oceanSymbol',0,4600,1000);b.fade(2,'treeSymbol',0,4600,1000);
    b.action(2,'cloudWest','move',{x:542,y:186},0,5000);
    b.fade(2,rain,0,4400,1400);
    b.action(2,'warmSurface','move',{x:78,y:0},0,5000);b.camera(2,1,0,0,5000);waveMotion(b,0);waveMotion(b,1);
    b.action(2,'waveNear','move',{x:18,y:1},0,5000);b.action(2,'waveFar','move',{x:-12,y:0},0,5000);
    b.scene.version='1.1';b.scene.stages[2].loopFromMs=6000;
    // The loop's camera is already still. All opaque moving scenery returns to its exact
    // boundary pose, while transport cohorts are invisible at both boundaries.
    for(const [id,start,end] of [['waveNear',18,27],['waveFar',-12,-20],['warmSurface',78,114]]){
        const y=id==='waveNear'?1:0;
        b.action(2,id,'move',{x:end,y},6000,3000);
        b.action(2,id,'move',{x:start,y},9000,3000);
    }
    b.action(2,surface,'flow',{flowOffset:44},6000,6000,{cycles:1,alternate:false},'linear');
    b.action(2,into,'flow',{flowOffset:22},6000,6000,{cycles:1,alternate:false},'linear');
    b.action(2,under,'flow',{flowOffset:4.4},6000,6000,{cycles:5,alternate:false},'linear');
    const cohort=b.particles('loopRain',Array.from({length:24},(_,i)=>[818+(i%8)*34,171+Math.floor(i/8)*17]),2,P.ocean,{initial:{opacity:0}});
    b.action(2,cohort,'move',{x:-15,y:239},6000,6000,{},'ease-in');
    b.fade(2,cohort,.72,6000,700);b.fade(2,cohort,0,11000,1000);
    for(const [id,x,y,dx,dy] of [['loopOceanVapour',281,433,35,-114],['loopTreeVapour',969,353,-18,-88]]){
        molecule(b,id,x,y,.38);
        b.scene.objects.find(o=>o.id===id).initial.opacity=0;
        b.action(2,id,'move',{x:x+dx,y:y+dy},6000,6000);
        b.fade(2,id,.6,6000,1200);b.fade(2,id,0,9800,2200);
    }
    const rise=b.circle(352,300,25,P.vapour,{id:'loopAir',layer:'mechanism',initial:{opacity:0}});
    b.action(2,rise,'move',{x:490,y:192},6000,6000);
    b.action(2,rise,'scale',{scaleX:1.7,scaleY:1.7},6000,6000);
    b.fade(2,rise,.25,6000,1200);b.fade(2,rise,0,9600,2400);
    const condensate=b.group('loopCondensate',523,164,{layer:'mechanism',initial:{opacity:0}});
    [[-18,-9],[7,3],[27,-5]].forEach(([x,y])=>b.circle(x,y,2.4,P.water,{parent:condensate,gradient:'gWater'}));
    b.action(2,condensate,'scale',{scaleX:1.7,scaleY:1.7},6000,6000);
    b.fade(2,condensate,.8,6800,1600);b.fade(2,condensate,0,10200,1800);
    scenes.push(['08-complete-cycle',b.finish()]);
}
// JSON is always materialized, never accepted merely because this generator authored it.
await fs.mkdir(OUT,{recursive:true});
for(const [slug,scene] of scenes)await fs.writeFile(path.join(OUT,`${slug}.json`),JSON.stringify(scene,null,2)+'\n');
await fs.writeFile(path.join(OUT,'manifest.json'),JSON.stringify({provenance:'authored-visual-benchmark',sceneVersions:['1.0','1.1'],palette:P,scenes:scenes.map(([slug,s])=>({file:`${slug}.json`,version:s.version,title:s.title,objects:s.objects.length,paths:s.paths.length,stages:3}))},null,2)+'\n');
console.log(scenes.map(([slug,s])=>`${slug}: ${s.objects.length} objects, ${s.paths.length} paths`).join('\n'));

#!/bin/sh
# builds the harness bundle and writes dist/h/<mock>.html for every mocks/*.json
cd "$(dirname "$0")/.." && npx vite build --config .cl2-harness/vite.config.ts 2>&1 | tail -3
node -e "
const fs=require('fs'),path=require('path');const d='.cl2-harness';
const m=JSON.parse(fs.readFileSync(d+'/dist/.vite/manifest.json'));
let js,css=[];for(const [k,v] of Object.entries(m)){if(v.isEntry&&k.endsWith('.ts'))js=v;if(v.isEntry&&k.endsWith('.css'))css.push(v.file);if(v.isEntry&&k.endsWith('.ts'))css.push(...(v.css||[]))}
fs.mkdirSync(d+'/dist/h',{recursive:true});
for(const f of fs.readdirSync(d+'/mocks').filter(x=>!x.startsWith('_'))){const n=f.replace('.json','');const pj=JSON.parse(fs.readFileSync(d+'/mocks/'+f,'utf8'));pj.props=Object.assign({},JSON.parse(fs.readFileSync(d+'/mocks/_base.json')),pj.props);pj.props.authHistoryBoundary={version:2,epoch:'01934f2a-7c3b-7d2e-9a41-0123456789ab'};const page=JSON.stringify(pj).replace(/</g,'\\\\u003c');
fs.writeFileSync(d+'/dist/h/'+n+'.html','<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><script>try{if(localStorage.getItem(\"appearance\")===\"dark\")document.documentElement.classList.add(\"dark\")}catch(e){}</script>'+css.map(c=>'<link rel=\"stylesheet\" href=\"/'+c+'\">').join('')+'<script type=\"module\" src=\"/'+js.file+'\"></script></head><body><script data-page=\"app\" type=\"application/json\">'+page+'</script><div id=\"app\"></div></body></html>')}
"

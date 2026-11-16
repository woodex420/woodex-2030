import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { JSDOM, VirtualConsole } from 'jsdom';
const entry = `import React,{useState} from 'react';import {createRoot} from 'react-dom/client';import {MemoryRouter,useNavigate} from 'react-router';import {Sidebar} from './apps/dashboard/src/components/layout/Sidebar';function Probe(){const [compact,setCompact]=useState(false);window.go=useNavigate();window.compact=()=>setCompact(v=>!v);return <Sidebar collapsed={compact}/>};createRoot(document.getElementById('root')).render(<MemoryRouter initialEntries={['/website/theme']}><Probe/></MemoryRouter>);`;
const built = await build({ stdin: { contents: entry, loader:'tsx', resolveDir:process.cwd() }, bundle:true, format:'iife', platform:'browser', write:false, jsx:'automatic', alias:{'@':process.cwd()+'/apps/dashboard/src'}, define:{'process.env.NODE_ENV':'"test"'}, plugins:[{name:'auth-fixture',setup(b){b.onResolve({filter:/^@\/lib\/auth$/},a=>({path:a.path,namespace:'auth'}));b.onLoad({filter:/.*/,namespace:'auth'},()=>({contents:'export const can=(cap)=>window.fixtureCaps.includes(cap);'}));}}] });
const pause=()=>new Promise(r=>setTimeout(r,150));
for(const caps of [[],['users.manage']]){
 const errors=[];const vc=new VirtualConsole();vc.on('jsdomError',e=>errors.push(e.message));vc.on('error',(...a)=>errors.push(a.join(' ')));
 const dom=new JSDOM('<div id="root"></div>',{url:'http://fixture.test',runScripts:'outside-only',pretendToBeVisual:true,virtualConsole:vc});const w=dom.window;
 try{
  w.fixtureCaps=caps;w.eval(built.outputFiles[0].text);await pause();
  const desktop=w.document.querySelector('aside');const group=label=>[...desktop.querySelectorAll('button[aria-expanded]')].find(b=>b.textContent.trim()===label);
  const active=()=>[...desktop.querySelectorAll('a[aria-current="page"]')].map(a=>a.getAttribute('href'));
  assert.deepEqual(active(),['/website/theme'],'Only the deepest destination is active');
  assert.equal(Boolean(desktop.querySelector('a[href="/login-locks"]')),caps.length>0,'Capability filter preserved');
  group('Website & Growth').click();await pause();assert.equal(Boolean(desktop.querySelector('a[href="/website/theme"]')),false,'Website group collapsed');
  w.go('/website/pages/example');await pause();assert.equal(group('Website & Growth').getAttribute('aria-expanded'),'true');assert.deepEqual(active(),['/website']);
  group('Finance').click();await pause();assert.equal(Boolean(desktop.querySelector('a[href="/ledger"]')),false,'Finance group collapsed');
  w.compact();await pause();const ledger=desktop.querySelector('a[href="/ledger"]');assert.ok(ledger,'Compact mode must not lose collapsed group destinations');assert.equal(ledger.getAttribute('aria-label'),'Ledger');
  w.compact();await pause();assert.equal(Boolean(desktop.querySelector('a[href="/ledger"]')),false,'Returning to expanded mode preserves manual collapsed state');
  w.go('/ledger');await pause();assert.equal(group('Finance').getAttribute('aria-expanded'),'true');assert.deepEqual(active(),['/ledger']);
  w.go('/website-other');await pause();assert.deepEqual(active(),[],'Path boundary must not activate /website');
  w.go('/');await pause();assert.deepEqual(active(),['/']);
  assert.deepEqual(errors,[]);console.log(`PASS actual dashboard sidebar: ${caps.length?'privileged':'restricted'} capability fixture`);
 }finally{w.close()}
}
console.log('2/2 sidebar fixture journeys passed: active route, group reveal, compact links and capability filtering; not browser/server authorization acceptance.');

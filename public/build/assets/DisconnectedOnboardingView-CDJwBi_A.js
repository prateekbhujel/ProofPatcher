import{c as s,A as g}from"./AppLayout-DWTSAajx.js";import{j as e,H as j,L as N}from"./app-BGKs9pAw.js";import{C as v}from"./copy-DPkaTqdy.js";/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const c={name:"circle-x",size:24,node:[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["path",{d:"m15 9-6 6",key:"1uzhvr"}],["path",{d:"m9 9 6 6",key:"z0biqf"}]],aliases:["x-circle"]};c.node;const D=s(c);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const n={name:"external-link",size:24,node:[["path",{d:"M15 3h6v6",key:"1q9fwt"}],["path",{d:"M10 14 21 3",key:"gplh6r"}],["path",{d:"M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6",key:"a6xqqp"}]]};n.node;const w=s(n);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const o={name:"git-branch",size:24,node:[["path",{d:"M15 6a9 9 0 0 0-9 9V3",key:"1cii5b"}],["circle",{cx:"18",cy:"6",r:"3",key:"1h7g24"}],["circle",{cx:"6",cy:"18",r:"3",key:"fqmcym"}]]};o.node;const _=s(o);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const i={name:"git-merge",size:24,node:[["circle",{cx:"18",cy:"18",r:"3",key:"1xkwt0"}],["circle",{cx:"6",cy:"6",r:"3",key:"1lh9wr"}],["path",{d:"M6 21V9a9 9 0 0 0 9 9",key:"7kw0sc"}]]};i.node;const q=s(i);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const x={name:"log-out",size:24,node:[["path",{d:"m16 17 5-5-5-5",key:"1bji2h"}],["path",{d:"M21 12H9",key:"dn1m92"}],["path",{d:"M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4",key:"1uf3rs"}]]};x.node;const L=s(x);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const m={name:"message-square",size:24,node:[["path",{d:"M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z",key:"18887p"}]]};m.node;const $=s(m);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const p={name:"plus",size:24,node:[["path",{d:"M5 12h14",key:"1ays0h"}],["path",{d:"M12 5v14",key:"s699le"}]]};p.node;const C=s(p);function H({size:a=36,className:r=""}){return e.jsx("img",{src:"/icon.svg",width:a,height:a,className:`shrink-0 ${r}`,alt:"","aria-hidden":"true"})}function V({error:a,deviceClientIdInput:r,setDeviceClientIdInput:h,isSubmittingDevice:l,handleInitiateDeviceFlow:u,handlePollDeviceFlow:y,copyDeviceCode:k,copiedDeviceCode:b,githubConnection:t}){const d=t==null?void 0:t.deviceFlow;return e.jsxs(g,{activeNav:"issues",children:[e.jsx(j,{title:"Connect GitHub · ProofPatcher"}),e.jsx("div",{className:"flex min-h-screen items-center justify-center px-4 py-12",children:e.jsxs("div",{className:"w-full max-w-md rounded-2xl border border-slate-200 bg-white p-7 shadow-sm dark:border-slate-800 dark:bg-[#141821]",children:[e.jsx(H,{size:40}),e.jsx("h1",{className:"mt-5 text-xl font-semibold tracking-tight",children:"Connect GitHub"}),e.jsx("p",{className:"mt-2 text-sm leading-relaxed text-slate-600 dark:text-slate-400",children:"Connect your account to see your repositories, issues and pull requests."}),a&&e.jsx("p",{role:"alert",className:"mt-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-200",children:a}),d?e.jsxs("div",{className:"mt-6 space-y-4",children:[e.jsx("p",{className:"text-sm text-slate-600 dark:text-slate-400",children:"Enter this code on GitHub, then return here."}),e.jsxs("div",{className:"flex items-center gap-2",children:[e.jsx("code",{className:"flex-1 rounded-lg bg-slate-100 px-4 py-3 text-center text-lg font-semibold tracking-widest dark:bg-slate-800",children:d.userCode}),e.jsx("button",{type:"button",onClick:()=>k(d.userCode),"aria-label":"Copy code",className:"rounded-lg border border-slate-300 p-3 dark:border-slate-700",children:e.jsx(v,{className:"h-4 w-4"})})]}),b&&e.jsx("p",{className:"text-xs text-slate-500",children:"Copied"}),e.jsxs("a",{href:d.verificationUri||"https://github.com/login/device",target:"_blank",rel:"noreferrer",className:"flex items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-medium dark:border-slate-700",children:["Open GitHub ",e.jsx(w,{className:"h-4 w-4"})]}),e.jsx("button",{type:"button",disabled:l,onClick:()=>y(),className:"w-full rounded-lg bg-violet-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-violet-800 disabled:opacity-50",children:l?"Checking…":"Check connection"})]}):e.jsxs("form",{onSubmit:u,className:"mt-6 space-y-4",children:[!(t!=null&&t.isConfigured)&&e.jsxs("div",{className:"space-y-2",children:[e.jsx("p",{className:"text-sm text-slate-500",children:"This installation needs a GitHub App client ID."}),e.jsx("label",{htmlFor:"onboarding-client-id",className:"block text-sm font-medium",children:"GitHub App client ID"}),e.jsx("input",{id:"onboarding-client-id",value:r,onChange:f=>h(f.target.value),placeholder:"Enter client ID",autoComplete:"off",className:"w-full rounded-lg border border-slate-300 bg-transparent px-3 py-2 text-sm dark:border-slate-700"})]}),e.jsxs("button",{type:"submit",disabled:l||!(t!=null&&t.isConfigured)&&!r.trim(),className:"flex w-full items-center justify-center gap-2 rounded-lg bg-violet-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-violet-800 disabled:opacity-50",children:[e.jsx(_,{className:"h-4 w-4"}),l?"Connecting…":"Continue with GitHub"]}),e.jsx("p",{className:"text-xs text-slate-500",children:"You’ll enter a short code on GitHub."})]}),e.jsx(N,{href:"/settings",className:"mt-5 inline-block text-sm text-slate-500 hover:text-violet-700 dark:hover:text-violet-300",children:"Settings"})]})})]})}export{D as C,V as D,w as E,q as G,L,$ as M,C as P};

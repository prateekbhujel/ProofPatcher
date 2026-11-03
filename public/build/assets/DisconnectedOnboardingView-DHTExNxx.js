import{c as t,B as j}from"./AppLayout-B4n1uL6t.js";import{r,j as e,H as v,L as c}from"./app-DilD70mZ.js";import{S as N}from"./SignInWithGitHubButton-DAd1vU-p.js";/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const o={name:"bold",size:24,node:[["path",{d:"M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8",key:"mg9rjx"}]]};o.node;const I=t(o);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const l={name:"circle-x",size:24,node:[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["path",{d:"m15 9-6 6",key:"1uzhvr"}],["path",{d:"m9 9 6 6",key:"z0biqf"}]],aliases:["x-circle"]};l.node;const D=t(l);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const d={name:"code",size:24,node:[["path",{d:"m16 18 6-6-6-6",key:"eg8j8"}],["path",{d:"m8 6-6 6 6 6",key:"ppft3o"}]]};d.node;const H=t(d);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const m={name:"git-merge",size:24,node:[["circle",{cx:"18",cy:"18",r:"3",key:"1xkwt0"}],["circle",{cx:"6",cy:"6",r:"3",key:"1lh9wr"}],["path",{d:"M6 21V9a9 9 0 0 0 9 9",key:"7kw0sc"}]]};m.node;const G=t(m);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const x={name:"italic",size:24,node:[["line",{x1:"19",x2:"10",y1:"4",y2:"4",key:"15jd3p"}],["line",{x1:"14",x2:"5",y1:"20",y2:"20",key:"bu0au3"}],["line",{x1:"15",x2:"9",y1:"4",y2:"20",key:"uljnxc"}]]};x.node;const S=t(x);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const h={name:"link-2",size:24,node:[["path",{d:"M9 17H7A5 5 0 0 1 7 7h2",key:"8i5ue5"}],["path",{d:"M15 7h2a5 5 0 1 1 0 10h-2",key:"1b9ql8"}],["line",{x1:"8",x2:"16",y1:"12",y2:"12",key:"1jonct"}]]};h.node;const E=t(h);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const p={name:"list",size:24,node:[["path",{d:"M3 5h.01",key:"18ugdj"}],["path",{d:"M3 12h.01",key:"nlz23k"}],["path",{d:"M3 19h.01",key:"noohij"}],["path",{d:"M8 5h13",key:"1pao27"}],["path",{d:"M8 12h13",key:"1za7za"}],["path",{d:"M8 19h13",key:"m83p4d"}]]};p.node;const $=t(p);/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const u={name:"message-square",size:24,node:[["path",{d:"M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z",key:"18887p"}]]};u.node;const L=t(u),i="proofpatcher-onboarding-mascot-seen";function _(a){if(!a)return!1;try{return a.getItem(i)!==null?!1:(a.setItem(i,"1"),!0)}catch{return!1}}function q({error:a,githubConnection:s,redirectTo:y="/issues"}){const[n,f]=r.useState((s==null?void 0:s.clientId)||""),[g,k]=r.useState(!1);return r.useEffect(()=>{try{_(window.sessionStorage)&&k(!0)}catch{}},[]),e.jsxs(e.Fragment,{children:[e.jsx(v,{title:"Connect GitHub"}),e.jsx("div",{className:"flex min-h-screen items-center justify-center px-4 py-12",style:{background:"radial-gradient(640px 420px at 50% 42%, var(--accent-soft) 0%, var(--bg) 72%)"},children:e.jsxs("div",{className:"w-full max-w-md border border-[var(--border)] bg-[var(--bg-raised)] p-6 shadow-lg",children:[e.jsxs("div",{className:"flex items-center justify-between gap-3",children:[e.jsxs("div",{className:"flex items-center gap-2.5",children:[e.jsx(j,{size:30}),e.jsx("p",{className:"text-sm font-bold leading-tight",children:"proofpatcher"})]}),e.jsx("img",{src:"/mascot.png",width:64,height:64,alt:"","aria-hidden":"true",className:g?"onboarding-mascot-welcome shrink-0":"shrink-0"})]}),e.jsx("h1",{className:"mt-5 text-base font-semibold",children:"Connect GitHub"}),e.jsx("p",{className:"mt-1 text-xs leading-relaxed text-[var(--fg-muted)]",children:"Sign in to load your repositories, issues and pull requests. Nothing runs on GitHub until you connect."}),a&&e.jsx("p",{role:"alert",className:"mt-4 border border-rose-300 bg-rose-50 p-2.5 text-xs text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200",children:a}),e.jsxs("div",{className:"mt-5 space-y-3",children:[!(s!=null&&s.isConfigured)&&e.jsxs("div",{className:"space-y-1.5",children:[e.jsx("p",{className:"text-xs text-[var(--fg-muted)]",children:"This installation needs a GitHub App client ID."}),e.jsx("label",{htmlFor:"onboarding-client-id",className:"block text-xs font-semibold",children:"GitHub App client ID"}),e.jsx("input",{id:"onboarding-client-id",value:n,onChange:b=>f(b.target.value),placeholder:"Enter client ID",autoComplete:"off",className:"w-full border border-[var(--border-strong)] bg-transparent px-2.5 py-2 text-xs"})]}),e.jsx(N,{redirectTo:y,clientId:n,className:"w-full"}),e.jsx("p",{className:"text-[11px] text-[var(--fg-muted)]",children:"GitHub will ask you to authorize ProofPatcher, then bring you back here."})]}),e.jsxs("div",{className:"mt-5 flex items-center justify-between border-t border-[var(--border)] pt-3 text-xs",children:[e.jsx(c,{href:"/settings",className:"text-[var(--fg-muted)] hover:text-[var(--fg)] hover:underline",children:"settings"}),e.jsx(c,{href:"/",className:"text-[var(--fg-muted)] hover:text-[var(--fg)] hover:underline",children:"console"})]})]})})]})}export{I as B,H as C,q as D,G,S as I,$ as L,L as M,E as a,D as b};

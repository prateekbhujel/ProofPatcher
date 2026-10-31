import{r as l,j as a,L as f}from"./app-BGKs9pAw.js";/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const B=t=>t==null?void 0:t.replace(/([a-z0-9])([A-Z])/g,"$1-$2").toLowerCase();/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function P(t,e,r=[]){if(e==null)throw new Error("[lucide]: iconNode is required when icon name is used");return{name:B(t),size:24,node:e,...r.length>0?{aliases:r}:{}}}/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const E=t=>{let e="",r=!1;for(const s of t){if(s==="-"||s==="_"||s<=" "){r=e.length>0;continue}e.length===0?e+=s.toLowerCase():e+=r?s.toUpperCase():s,r=!1}return e};/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const I=t=>{const e=E(t);return e.charAt(0).toUpperCase()+e.slice(1)};/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const v=(...t)=>t.filter((e,r,s)=>!!e&&e.trim()!==""&&s.indexOf(e)===r).join(" ").trim();/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const i={xmlns:"http://www.w3.org/2000/svg",width:24,height:24,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor","stroke-width":2,"stroke-linecap":"round","stroke-linejoin":"round"};/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function j(t){return t!=null}function R(t,e={}){var g,k;const r=e.attributeNames??{},s=o=>r[o]??o,d=t.size??t.width??i.width,u=t.size??t.height??i.height,x=((g=t.aliases)==null?void 0:g.filter(o=>typeof o=="string"&&o.trim()!=="").map(o=>`lucide-${o}`))??[],b=[...t.name?[`lucide-${t.name}`]:[],...x],n=((k=e.className)==null?void 0:k.split(" ").filter(Boolean))??[],m=e.includeDefaultClasses===!1?v(...n):v("lucide",...b,...n),N=e.absoluteStrokeWidth?Number(e.strokeWidth??i["stroke-width"])*Number(t.size??t.width??i.width)/Number(e.size??e.width??i.width):e.strokeWidth??i["stroke-width"];return["svg",{...Object.entries(i).reduce((o,[c,h])=>(o[s(c)]=h,o),{}),..."color"in e&&e.color&&{[s("stroke")]:e.color},..."size"in e&&j(e.size)&&{[s("width")]:e.size,[s("height")]:e.size},..."width"in e&&j(e.width)&&{[s("width")]:e.width},..."height"in e&&j(e.height)&&{[s("height")]:e.height},[s("stroke-width")]:N,...m&&{[s("class")]:m},[s("viewBox")]:`0 0 ${d} ${u}`,...e.hasA11yProp===!1?{[s("aria-hidden")]:"true"}:{},..."attributes"in e&&e.attributes},t.node.map(o=>{const[c,h,w]=o,p=e.nonScalingStroke?{[s("vector-effect")]:"non-scaling-stroke",...h}:h;return w?[c,p,w]:[c,p]})]}/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function _(t,e={}){return R(t,{...e,attributeNames:{...e.attributeNames,class:"className","stroke-width":"strokeWidth","stroke-linecap":"strokeLinecap","stroke-linejoin":"strokeLinejoin","vector-effect":"vectorEffect"}})}/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const D=t=>{for(const e in t)if(e.startsWith("aria-")||e==="role"||e==="title")return!0;return!1},q=l.createContext({}),F=()=>l.useContext(q),H=l.forwardRef(({color:t,size:e,width:r,height:s,strokeWidth:d,absoluteStrokeWidth:u,nonScalingStroke:x,className:b="",children:n,iconNode:m=[],icon:N={node:m,aliases:[],size:24},...C},g)=>{const{size:k=24,strokeWidth:o=2,absoluteStrokeWidth:c=!1,nonScalingStroke:h=!1,color:w="currentColor",className:p=""}=F()??{},A=!!n||D(C),[S,z,L=[]]=_(N,{color:t??w,width:r??e??k,height:s??e??k,strokeWidth:d??o,absoluteStrokeWidth:u??c,nonScalingStroke:x??h,className:v(p,b),hasA11yProp:A,attributes:C});return l.createElement(S,{ref:g,...z},[...L.map(([W,$])=>l.createElement(W,$)),...Array.isArray(n)?n:[n]])});/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function K(t,e=[],r=[]){const s=typeof t=="string"?P(t,e,r):t,d=l.forwardRef(({className:u,...x},b)=>l.createElement(H,{ref:b,icon:s,className:u,...x}));return s.name&&(d.displayName=I(s.name)),d}/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const y={name:"arrow-left",size:24,node:[["path",{d:"m12 19-7-7 7-7",key:"1l729n"}],["path",{d:"M19 12H5",key:"x3x0zl"}]]};y.node;const U=K(y);function T({activeNav:t,children:e}){const r=t==="issues";return a.jsxs("div",{className:"min-h-screen bg-slate-50 text-slate-900 dark:bg-[#0e1117] dark:text-slate-100",children:[!r&&a.jsx("header",{className:"sticky top-0 z-30 border-b border-slate-200/80 dark:border-slate-800 bg-white/95 dark:bg-[#141821]/95 backdrop-blur-xs",children:a.jsxs("div",{className:"mx-auto max-w-6xl h-12 px-4 sm:px-6 flex items-center justify-between gap-4",children:[a.jsxs("div",{className:"flex items-center gap-3 min-w-0",children:[a.jsxs(f,{href:"/issues",className:"inline-flex items-center gap-2 text-xs font-semibold text-slate-700 hover:text-violet-700 dark:text-slate-200 dark:hover:text-violet-300 transition",children:[a.jsx(U,{className:"h-3.5 w-3.5"}),"Back to workspace"]}),a.jsx("span",{className:"text-slate-300 dark:text-slate-700 hidden sm:inline",children:"|"}),a.jsx("span",{className:"text-[11px] font-mono text-slate-500 dark:text-slate-400 hidden sm:inline",children:"ProofPatcher Verification Kernel"})]}),a.jsxs("nav",{className:"flex items-center gap-1.5 text-xs shrink-0",children:[a.jsx(f,{href:"/issues",className:"px-2.5 py-1 rounded-md border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 font-medium transition",children:"Issues & Tasks"}),a.jsx(f,{href:"/repositories",className:`px-2.5 py-1 rounded-md border font-medium transition ${t==="repositories"||t==="pulls"?"border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900":"border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200"}`,children:"Pull Requests"}),a.jsx(f,{href:"/checks",className:`px-2.5 py-1 rounded-md border font-medium transition ${t==="checks"?"border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900":"border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200"}`,children:"Checks"}),a.jsx(f,{href:"/settings",className:`px-2.5 py-1 rounded-md border font-medium transition ${t==="settings"?"border-slate-900 bg-slate-900 text-white dark:border-slate-100 dark:bg-slate-100 dark:text-slate-900":"border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200"}`,children:"Settings"})]})]})}),a.jsx("main",{className:r?"min-h-screen w-full":"mx-auto max-w-6xl px-4 py-6 sm:px-6",children:e})]})}export{T as A,K as c};

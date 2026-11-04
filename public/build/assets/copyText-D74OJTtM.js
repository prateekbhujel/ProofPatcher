import{c as n}from"./AppLayout-Dbnqk3u8.js";/**
 * @license lucide-react v1.62.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const c={name:"copy",size:24,node:[["rect",{width:"14",height:"14",x:"8",y:"8",rx:"2",ry:"2",key:"17jyea"}],["path",{d:"M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2",key:"zix9uf"}]]};c.node;const i=n(c);async function d(t){var o;try{if(typeof navigator<"u"&&((o=navigator.clipboard)!=null&&o.writeText))return await navigator.clipboard.writeText(t),!0}catch{}try{if(typeof document>"u")return!1;const e=document.createElement("textarea");e.value=t,e.style.position="fixed",e.style.opacity="0",document.body.appendChild(e),e.select();const a=document.execCommand("copy");return document.body.removeChild(e),a}catch{return!1}}export{i as C,d as c};

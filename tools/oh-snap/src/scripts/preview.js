/** SNAPSMACK_EOF_HEADER: preview from the actual CMS-rendered starter template. */
const OhSnapPreview = (() => {
    let frame=null, project=null, skin=null, view='post', selected=0, signature='';
    const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    function image(post={}) {
        const url=post.cover_url || post.thumb_url || '';
        if (/^https:\/\//i.test(url)) return url;
        const width=Math.min(2400,Math.max(1,Number(post.width)||1200)), height=Math.min(2400,Math.max(1,Number(post.height)||800));
        const colour=/^#[a-f0-9]{6}$/i.test(post.colour || '') ? post.colour : '#51606b';
        const svg=`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><rect width="100%" height="100%" fill="${colour}"/><text x="50%" y="50%" text-anchor="middle" font-family="sans-serif" font-size="32" fill="#ffffff">${escape(post.title || 'Photograph')}</text></svg>`;
        return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    }
    function init(target, data) {
        frame=target; skin=data; project=null; view='post'; signature='';
        frame.title='Connected skin CSS study — verify the actual site using Open in browser';
    }
    function initOffline(target, value) {
        frame=target; skin=null; project=value; view='post'; selected=0; signature='';
        frame.title='New skin preview — same reviewed starter template as the exported package';
        build();
    }
    function setProject(value) {
        project=value;
        if (!frame) return;
        const next=JSON.stringify([value.mode,value.preview_content,value.name]);
        if (signature!==next) {signature=next;build();} else writeStyles();
    }
    function cssText() {
        const mode=window.OH_SNAP_CONTRACT.modes[project?.mode || 'SMACKONEOUT'];
        const vars=skin?.css_variables || mode.variables;
        const base=project?.base_css || skin?.style_css || mode.shell_css;
        const css=`${base}\n${OhSnapPolicy.variablesCss(project?.overrides || controlsGetOverrides(),vars)}\n${project?.user_css || controlsGetUserCss()}`;
        OhSnapPolicy.validateCss(css); return css;
    }
    function writeStyles() {
        if (!frame || !project) return;
        try {const el=frame.contentDocument?.getElementById('ohsnap-design');if(el)el.textContent=cssText();}
        catch(err){window.showStatus?.(`Preview unchanged: ${err.message}`,true);}
    }
    function build() {
        if (!frame || !project) return;
        let css;
        try {css=cssText();}catch(err){window.showStatus?.(`Preview unchanged: ${err.message}`,true);return;}
        const kind=view==='post'?(project.mode==='SMACKONEOUT'?'photo':'post'):view;
        const html=window.OH_SNAP_CONTRACT.previews[kind];
        if (!html) return;
        const doc=new DOMParser().parseFromString(html,'text/html');
        doc.querySelectorAll('link,script,style').forEach(el=>el.remove());
        const csp=doc.createElement('meta');csp.httpEquiv='Content-Security-Policy';csp.content="default-src 'none'; img-src data: https:; style-src 'unsafe-inline'; font-src 'none'; form-action 'none'; base-uri 'none'";doc.head.prepend(csp);
        const style=doc.createElement('style');style.id='ohsnap-design';style.textContent=css;doc.head.appendChild(style);
        doc.title=project.name;
        const content=project.preview_content || {}, posts=content.posts || [];
        const lead=posts[Math.min(selected,Math.max(0,posts.length-1))] || {};
        doc.querySelector('[data-preview-site]').textContent=content.site_name || 'My Photo Blog';
        doc.querySelector('[data-preview-tagline]').textContent=content.tagline || '';
        const fill=(node,post,index)=>{
            node.querySelectorAll('[data-preview-title]').forEach(el=>el.textContent=post.title || 'Untitled');
            node.querySelectorAll('[data-preview-caption]').forEach(el=>{el.textContent=post.description || 'Your caption will appear here.';});
            node.querySelectorAll('[data-preview-image]').forEach(el=>{el.src=image(post);el.alt=post.title || '';el.width=Number(post.width)||1200;el.height=Number(post.height)||800;});
            node.querySelectorAll('[data-preview-link]').forEach(el=>el.href=`#ohsnap-post-${index}`);
        };
        const grid=doc.getElementById('browse-grid');
        if(grid){
            const card=grid.querySelector('[data-preview-card]');grid.replaceChildren();
            posts.forEach((post,index)=>{const clone=card.cloneNode(true);fill(clone,post,index);grid.appendChild(clone);});
            if(!posts.length){const empty=doc.createElement('p');empty.className='empty-state';empty.textContent='No photographs to show yet.';grid.after(empty);}
            doc.querySelector('[data-preview-heading]').textContent=view==='landing'?'Latest photographs':'Archive';
        } else if (view==='page') {
            doc.querySelector('[data-preview-title]').textContent='About the photographer';
            doc.querySelector('[data-preview-caption]').textContent=content.about || 'This is a static page. Use this view to check text measure, headings, links and spacing.';
        } else fill(doc,lead,selected);
        if (!posts.length && view==='post') {doc.querySelector('.single-post').textContent='No photographs to show yet. Switch to Archive or add preview content.';}
        frame.onload=()=>{writeStyles();frame.contentDocument?.addEventListener('click',event=>{
            const link=event.target.closest('a');if(!link)return;event.preventDefault();
            const href=link.getAttribute('href') || '';
            if(href.startsWith('#ohsnap-post-')){selected=Number(href.split('-').pop())||0;switchView('post');}
            else if(href.includes('previous')){selected=Math.max(0,selected-1);build();}
            else if(href.includes('next')){selected=Math.min(posts.length-1,selected+1);build();}
            else if(href.includes('about'))switchView('page');
            else if(href.includes('archive'))switchView('archive');
            else if(href==='/')switchView('landing');
        });};
        frame.srcdoc='<!doctype html>\n'+doc.documentElement.outerHTML;
        document.getElementById('preview-placeholder')?.classList.add('hidden');
    }
    function applyOverrides(values){if(project)project.overrides={...values};writeStyles();}
    function switchView(value){view=value;document.querySelectorAll('.preview-view-btn').forEach(button=>button.classList.toggle('active',button.dataset.view===value));build();}
    return {init,initOffline,setProject,applyOverrides,switchView};
})();
// ===== SNAPSMACK EOF =====

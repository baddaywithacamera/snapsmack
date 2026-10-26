"""GYSS transport shared by the desktop UI and CLI. SNAPSMACK_EOF_HEADER."""
import requests
import snap_site_scope

def _site_scope(site):
    return snap_site_scope.header(site)

def repaired(value):
    if isinstance(value, str): return value.replace("/uploads/img_uploads/", "/img_uploads/")
    if isinstance(value, list): return [repaired(item) for item in value]
    if isinstance(value, dict): return {key: repaired(item) for key, item in value.items()}
    return value

class API:
    def __init__(self,p):
        self.base=p["site_url"].rstrip("/"); self.s=requests.Session(); self.s.headers.update({"Authorization":"Bearer "+p.get("api_key",""),"Accept":"application/json",**_site_scope(self.base)})
    def call(self,method,endpoint,params=None,body=None,timeout=90):
        q={"route":"gyss/"+endpoint}; q.update(params or {}); r=self.s.request(method,self.base+"/api.php",params=q,json=body,timeout=timeout)
        try:data=r.json()
        except Exception:raise RuntimeError(f"{endpoint.replace('-', ' ').title()} failed: the site returned HTTP {r.status_code}, not a GYSS response.")
        if not r.ok or not data.get("ok",False):raise RuntimeError(data.get("error") or f"HTTP {r.status_code}")
        return repaired(data)
    def ping(self):return self.call("GET","ping",timeout=25)
    def meta(self):return self.call("GET","meta")
    def photos(self,f):
        try:return self.call("GET","photos",f)
        except RuntimeError as exc:
            # Older/irregular installs can lack one of the optional tables used
            # by the filtered endpoint.  The library endpoint is deliberately
            # schema-tolerant, so use it as a read-only compatibility path and
            # perform the small requested slice here.
            if "HTTP 500" not in str(exc):raise
            data=self.library(); rows=list(data.get("images",[])); cats={}; albums={}
            for image_id,value in data.get("cat_map",[]):cats.setdefault(str(image_id),[]).append(value)
            for image_id,value in data.get("album_map",[]):albums.setdefault(str(image_id),[]).append(value)
            category=f.get("category_id"); album=f.get("album_id")
            if category is not None:rows=[x for x in rows if category in cats.get(str(x.get("id")),[])]
            if album is not None:rows=[x for x in rows if album in albums.get(str(x.get("id")),[])]
            limit=max(1,min(int(f.get("limit",200)),500))
            return {"ok":True,"total":len(rows),"photos":rows[:limit],"compatibility_fallback":True}
    def library(self,since=None):return self.call("GET","library",{"since":since} if since else None,timeout=180)
    def batch(self,u):return self.call("POST","batch-update",body={"updates":u},timeout=180)
    def gram_posts(self):return self.call("GET","gram-posts",{"limit":500})
    def gram_order(self,ids):return self.call("POST","gram-reorder",body={"ids":ids})
    def gram_carousel(self,ids,cover):return self.call("POST","gram-carousel",body={"ids":ids,"cover_post_id":cover})
    def audit(self):return self.call("GET","enrichment-audit",{"limit":1000},timeout=180)
    def enrich(self,i,prompt,fields,overwrite,force):return self.call("POST","enrich-one",body={"id":i,"prompt":prompt,"fields":fields,"overwrite":overwrite,"force_refresh":force},timeout=300)

# ===== SNAPSMACK EOF =====

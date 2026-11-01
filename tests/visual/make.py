"""Build two test cuts (A, B) from photo 'shots', with a known mapping, for testing picture matching."""
import json, os, random, subprocess, numpy as np, skimage.data as d
from skimage.color import gray2rgb
from skimage.transform import resize
from skimage.io import imsave
FPS=24; W,H=640,360
os.makedirs('src', exist_ok=True); os.makedirs('seg', exist_ok=True)
names=['astronaut','coffee','chelsea','rocket','hubble_deep_field','immunohistochemistry','retina','camera','coins','clock','horse','moon','brick','grass','gravel','colorwheel','shepp_logan_phantom','page']
def img(n):
    im=getattr(d,n)()
    im=np.asarray(im, dtype=float)
    if im.max()>1.5: im=im/255.0
    if im.ndim==2: im=gray2rgb(im)
    if im.shape[2]==4: im=im[:,:,:3]
    im=resize(im,(720,1280),anti_aliasing=True); return (np.clip(im,0,1)*255).astype('uint8')
# 18 source shots, each 8 s of slow pan/zoom over a photo
moves=['zoom','panl','panr','zoomout']
for i,n in enumerate(names):
    p=f'src/{i:02d}.png'
    if not os.path.exists(p): imsave(p, img(n))
    out=f'src/{i:02d}.webm'
    if os.path.exists(out): continue
    m=moves[i%4]
    z={'zoom':"z='1+0.0015*on'",'zoomout':"z='1.4-0.0015*on'",'panl':"z=1.25",'panr':"z=1.25"}[m]
    x={'zoom':"x='iw/2-(iw/zoom/2)'",'zoomout':"x='iw/2-(iw/zoom/2)'",'panl':"x='(iw-iw/zoom)*on/192'",'panr':"x='(iw-iw/zoom)*(1-on/192)'"}[m]
    vf=f"zoompan={z}:{x}:y='ih/2-(ih/zoom/2)':d=192:s={W}x{H}:fps={FPS}"
    subprocess.run(['ffmpeg','-y','-loglevel','error','-loop','1','-i',p,'-vf',vf,'-frames:v','192','-c:v','libvpx-vp9','-b:v','0','-crf','34','-deadline','realtime','-cpu-used','8',out],check=True)
GRADE="eq=contrast=1.35:saturation=0.35:gamma=0.85:brightness=0.03,colorbalance=rs=0.25:gs=0.05:bs=-0.2"
REFRAME=f"crop=iw*0.82:ih*0.82,scale={W}:{H}"
# A: shots 0..15, each source 1.0..6.0 s (5 s long)
A=[dict(shot=i, src_in=1.0, src_out=6.0) for i in range(16)]
# B: reorder, trim, remove, reuse, regrade, reframe, insert new
B=[]
order=[0,1,2,4,5,6,7,8,9,12,11,13,14,15,16,5]
for i in order:
    s=dict(shot=i, src_in=1.0, src_out=6.0)
    if i==4: s['src_in']=2.5          # head trimmed
    if i==7: s['src_out']=4.0         # tail trimmed
    if i in (8,12): s['fx']='grade'   # regraded
    if i==14: s['fx']='reframe'       # reframed
    B.append(s)
B[-1]=dict(shot=5, src_in=1.5, src_out=3.5)  # shot 5 used twice (ambiguous)
# 3 and 10 removed; 16 is new
def render(edl, name):
    files=[]
    for k,s in enumerate(edl):
        f=f"seg/{name}_{k:02d}.webm"; vf=[]
        if s.get('fx')=='grade': vf.append(GRADE)
        if s.get('fx')=='reframe': vf.append(REFRAME)
        cmd=['ffmpeg','-y','-loglevel','error','-ss',f"{s['src_in']:.4f}",'-i',f"src/{s['shot']:02d}.webm",'-t',f"{s['src_out']-s['src_in']:.4f}"]
        if vf: cmd+=['-vf',','.join(vf)]
        cmd+=['-r',str(FPS),'-c:v','libvpx-vp9','-b:v','0','-crf','34','-deadline','realtime','-cpu-used','8','-an',f]
        subprocess.run(cmd,check=True); files.append(f)
    open(f'{name}.txt','w').write(''.join(f"file '{f}'\n" for f in files))
    subprocess.run(['ffmpeg','-y','-loglevel','error','-f','concat','-safe','0','-i',f'{name}.txt','-c:v','libvpx-vp9','-b:v','0','-crf','34','-deadline','realtime','-cpu-used','8','-g','48','-r',str(FPS),f'{name}.webm'],check=True)
def timeline(edl):
    t=0; out=[]
    for s in edl:
        L=s['src_out']-s['src_in']; out.append(dict(s, rec_in=t, rec_out=t+L)); t+=L
    return out
import sys
if 'notes' not in sys.argv: render(A,'cutA'); render(B,'cutB')
TA,TB=timeline(A),timeline(B)
random.seed(7); notes=[]
extra=[4*5+0.4, 4*5+1.2, 7*5+3.6, 7*5+4.5, 5*5+1.0, 5*5+2.0, 8*5+2.5, 12*5+1.0, 14*5+2.2]
for k in range(48+len(extra)):
    t=(round(random.uniform(0.2, TA[-1]['rec_out']-0.2)*FPS) if k<48 else round(extra[k-48]*FPS))/FPS
    seg=next(s for s in TA if s['rec_in']<=t<s['rec_out']); src=seg['src_in']+(t-seg['rec_in'])
    hits=[s for s in TB if s['shot']==seg['shot']]
    exact=[s for s in hits if s['src_in']<=src<s['src_out']]
    truth=[round((s['rec_in']+src-s['src_in'])*FPS) for s in exact]
    kind='removed' if not hits else 'trimmed' if not exact else 'ambiguous' if len(exact)>1 else ('graded' if exact[0].get('fx') else 'same')
    notes.append(dict(n=k, frameA=round(t*FPS), shot=seg['shot'], truth=truth, kind=kind, fx=exact[0].get('fx') if exact else None))
json.dump(dict(fps=FPS,A=TA,B=TB,notes=notes), open('truth.json','w'), indent=1)
from collections import Counter; print(Counter(n['kind'] for n in notes)); print('A', TA[-1]['rec_out'], 'B', TB[-1]['rec_out'])

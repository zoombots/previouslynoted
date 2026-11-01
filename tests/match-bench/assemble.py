core=open('/tmp/claude-0/bench/js/core.js').read()
core=core.replace("'use strict';\n","",1)
core=core.replace("/* New matcher core (pure functions). Assembled into assets/match.js once it scores well. */\n","")
core=core.replace("let CUT_REL = 4, CUT_ABS = 0.05;\nfunction setCut(r, a) { CUT_REL = r; CUT_ABS = a; }\n","const CUT_REL = 4, CUT_ABS = 0.05;\n")
core='\n'.join(l for l in core.split('\n') if not l.startswith('module.exports'))
core='\n'.join(('  '+l if l.strip() else l) for l in core.split('\n'))
cur=open('/home/claude/previouslynoted/assets/match.js').read()
header=cur[:cur.index("  const SAMPLE = 0.25;")]
browser=open('/tmp/claude-0/bench/js/browser_part.js').read()
footer=cur[cur.index("\n  const api = {"):]
open('/home/claude/previouslynoted/assets/match.js','w').write(header+core.lstrip('\n')+browser+footer)

# Previously Noted

<img src="assets/icons/icon.svg" width="96" alt="Previously Noted icon: a note carried from the previous cut onto the new one">

A self-hosted tool for following review notes across versions of a cut. It plays the video with the notes in sync, keeps a status history for every note, and carries unfinished notes into the next version so nothing is lost.

Built for shared hosting: PHP 8.1+ and SQLite, no build step, no Node, no database server to set up.

## What it does

- **Synced playback.** The current note appears under the picture as the video plays, frame-accurate. Click any note to jump to it.
- **Zoomable notes timeline.** One lane per note type (E, N, A). Scroll over it to zoom, drag to move along, or use the slider. Zoomed in, each note shows its text; notes too close together at the current zoom become a numbered group you can click to zoom into. With an edit imported, the shots appear as a strip above the lanes. The bar underneath is the whole cut, with the zoomed part outlined.
- **Edit import (OpenTimelineIO).** Import each version's edit: an `.otio` file, an FCP7 XML from Premiere or Resolve, or a CMX3600 EDL from Avid. The readers follow OpenTimelineIO's own adapters and were tested against them.
- **Automatic placement of carried notes.** With the edit (or transcript) imported for both cuts, carried notes move to where their moment is in the new cut: picture notes follow the shot and source frame, narration notes follow their line. Notes whose shot was removed, trimmed or reused, or whose line was reworded or dropped, are flagged with the reason so someone can check them.
- **Frame.io connection** (sign in with Adobe). Link each version to its Frame.io file: comments sync automatically (no CSV export), the video streams straight from Frame.io, and the next cut can be added straight from the version stack.
- **ElevenLabs transcription.** One click transcribes a linked cut from Frame.io, with speakers labelled and your film's names and terms spelled right.
- **Tracks.** Notes sit on tracks (Edit, Narration, Graphics to start). Add your own (Music, Sound, VFX…), switch tracks on and off, and drag a note up or down on the timeline to move it to another track.
- **Script view.** Three columns line by line: what's in this cut, the notes on that line, and the new script. Rewrite, cut or add lines with full history and approval, then download a recording script for the VO session. Lines recorded as written are flagged when the next cut's transcript arrives.
- **Transcripts with history.** Upload a transcript per version (.srt, .vtt or timecoded text). It scrolls along with playback, shows the current line under the video, lists each line's notes, and marks lines that are new or reworded since the previous cut, plus the lines that are gone. Every upload is kept.
- **Frame.io import.** Import the comment export (CSV) for each version. Replies attach to their notes, the director's E / N / A prefixes become note types, and Frame.io's "Complete" becomes *Addressed*. Re-importing a newer export of the same file only adds what's new.
- **Status with history.** Open → Addressed → Approved, plus To discuss, Won't do, Cut from film and Superseded. Every change records who, when, on which version, and an optional reason.
- **Carry forward.** When you add the next version, every note that isn't finished comes with it, marked *not placed*. Play the new cut, find each moment, press **Pin to playhead**. Notes keep their history across all versions.
- **Export.** Download every note for a film as a CSV, with its current status and its timecode in each version.
- **Accounts and roles.** Everyone signs in with their own email and password. Admins invite people by link and choose what each person can do: Admin, Editor, Reviewer or Viewer.

The video plays from the reviewer's own computer (or from a link). It is never uploaded to your server.

## Install on Namecheap (Stellar Plus)

1. **Point the domain.** If previouslynoted.com is registered at Namecheap, add it to your hosting in cPanel → **Domains** (or it's already the main domain). Note the folder cPanel shows for it: `public_html` for the main domain, or its own folder for an addon domain.
2. **Turn on HTTPS.** cPanel → **SSL/TLS Status** → run **AutoSSL** for the domain. Wait until it's green; it can take a few minutes after the domain starts pointing at the server.
3. **Set PHP.** cPanel → **Select PHP Version**: choose **8.2** or newer and click *Set as current*. On the **Extensions** tab, tick **pdo_sqlite** and **sqlite3** (and **mbstring** if it isn't already).
4. **Upload the installer.** cPanel → **File Manager**, open the domain's folder, and upload `install.php`.
5. **Run it.** Open `https://previouslynoted.com/install.php`. It checks the server, creates your admin account (name, email, password), installs everything, checks that the data folder can't be downloaded, and deletes itself.
6. **Open the app** at `https://previouslynoted.com`, sign in with your email and password, and invite the team from your name's menu → **People**.

To install into a subfolder instead (e.g. `/notes`), open *Install in a subfolder instead* on the installer page.

### Upgrading

Upload the newer `install.php` to the same place and open it. It finds the existing install, asks for an admin's email and password, replaces the app files, and keeps every note. For a subfolder install, open `install.php?folder=notes` (with your folder's name).

**Upgrading from 0.5 or earlier** (the shared team password): the installer asks for the team password one last time. Then open the app: it asks for the team password once more, with your name, email and a new password, and makes you the first admin. After that the team password stops working; invite everyone from **People**. Names already in the notes history stay as they are.

### Manual install (without the installer)

Upload the contents of `previouslynoted.zip` to the domain's folder and open the site; it runs the same first-time setup. Then check that `https://previouslynoted.com/data/notes.sqlite` says **403 Forbidden**.

## Connecting Frame.io and ElevenLabs

Both are optional. Without them, everything works with exported files as before.

### Frame.io

Frame.io V4 connects through Adobe. There are two ways; most accounts use the first.

**Sign in with Adobe (every plan).** One person signs in once; the app keeps the connection alive in the background and everyone on the team uses Frame.io through that sign-in. Use an Adobe account that can see the film's projects in Frame.io.

1. In the app, open **Settings**. Under Frame.io, keep **Sign in with Adobe** selected. It shows a **Default redirect URI** (for example `https://previouslynoted.com/oauth.php`) and a **Redirect URI pattern** (`https://previouslynoted\.com/oauth\.php`); keep the window open.
2. In the **Adobe Developer Console** (developer.adobe.com/console): **Create new project → Add API → Frame.io API → User Authentication → OAuth Web App**. Paste the two values from step 1 into **Default redirect URI** and **Redirect URI pattern**, and save.
3. In the project, open the **OAuth Web App** credential and copy the **Client ID** and **Client secret** into Settings.
4. Press **Save and sign in with Adobe**. Adobe asks you to sign in and approve; you come back to the app, connected. If you have more than one Frame.io account, choose the one with your projects.

Adobe's sign-in renews itself automatically while the app is in use. If nobody uses the Frame.io features for a couple of weeks, Adobe may end it (its default limit for unused sign-ins is 14 days); the app then says so, and **Sign in again** in Settings fixes it in a few seconds. Notes, links and transcripts are not affected.

**Server-to-server (Enterprise plans only).** No sign-in at all. Adobe only offers this credential on Frame.io Enterprise plans; on other plans it's greyed out in the Developer Console. If your plan includes it: add the Frame.io API with **Server-to-Server** authentication (you need the Developer role with the **Frame.io S2S API** product in the Admin Console), choose **Server-to-server** in Settings, and paste its client ID and secret.

### ElevenLabs

1. In ElevenLabs, go to **Developers → API keys**, create a key with **Speech to Text** access, and copy it.
2. In **Settings → ElevenLabs transcription**, paste the key and save. Leave the language empty to detect it, or enter `en`, `fr`…
3. Optional: in **Films & versions**, open **Names and terms for transcription** and list names, places and species. They're spelled right more often (adds about 20% to the cost).

Transcription uses Scribe (about $0.22 per hour of audio, so about 17 cents for a 45-minute cut). The cut's audio is sent to ElevenLabs: check your production agreements before using it on unreleased material.

### Using them

- **Link a version:** Films & versions → **Link…** next to Frame.io. Paste the file's Frame.io link (for a version imported from a CSV, the link is already filled in). If the file is in a version stack, pick which version it is. Its comments come across straight away.
- **Sync:** opening a linked version syncs its comments if it hasn't in five minutes; **Sync comments from Frame.io** at the bottom of the version menu syncs now (it shows when it last synced). Notes imported earlier from a CSV are recognised, not duplicated. Comments marked complete in Frame.io become *Addressed*.
- **Stream:** a linked version plays from Frame.io. Choose **Efficient**, **High quality** or **Original** next to the speed menu. Links expire; the app fetches a fresh one automatically and carries on from the same frame.
- **Next cut:** in **Add a version**, when the previous version is in a version stack, pick the new file from the **Frame.io file** list. The version is created, linked, and its comments synced.
- **Transcribe:** Transcript tab → **Transcribe this cut with ElevenLabs** (or **Transcribe** in Films & versions). It runs on the server; keep working, and it appears when done. Click a speaker's name to rename them throughout ("Speaker 1" → "Narrator").

### If something doesn't work

- **Wrong video link saved** (for example a Frame.io share link instead of the video file): the player says the link doesn't play and offers **Remove the saved link**. To switch at any time, hover the picture and press **Change video**, or use **Use a video link** again: it shows the saved link to replace, and **Remove this link** clears it. Links are checked before they're saved, and a Frame.io page link offers to link the version to Frame.io instead, which streams the real video.

- **Adobe shows an error instead of the sign-in page:** check the redirect URI and pattern in the Developer Console match exactly what Settings shows (including https).
- **"The Adobe sign-in for Frame.io has expired":** open Settings and press **Sign in again**.
- **"Adobe didn't issue a token"** (server-to-server): check the client ID and secret; if it mentions scope, check the Frame.io API with Server-to-Server is on the Developer Console project.
- **"Frame.io refused access":** the credential's product profile may not cover that project, or downloads are restricted for it.
- **Video won't stream:** try another quality. If Frame.io requires watermarking on the project, it may not provide a playable link; download the file and open it instead.
- **"The server stopped the transcription":** the host ended the long request. Try again; if it keeps happening, ask Namecheap whether long-running PHP requests are limited, or upload a transcript file instead.

## Everyday use

1. **Films & versions → Add film**, then **Add a version** for the cut you sent to the director.
2. On that version, import the **Comments** (the Frame.io comment export, the same kind of file as `RFTA Restructure Oct 14 - Part 1.csv`), the **Edit** (see below) and, if you have one, the **Transcript**.
3. Download the same version's video from Frame.io and **Open a video file**. Press space to play.
4. Work through the notes. Type a reply or a reason, then set the status. To change a note's wording, press **Reword** beside its text (editors and admins can reword any note; reviewers their own). The earlier wording stays in the note's history, and a reworded Frame.io comment keeps its new wording when comments are synced again (Frame.io itself isn't changed).
5. When the next cut is ready, **Add a version** with *Carry unfinished notes* selected, and import its edit and transcript. Carried notes are placed automatically; the toast says how many need a look.
6. Filter by **Needs placing or checking** and go through what's left. Import the new cut's Frame.io comments too: new notes join the carried ones.

### Getting notes out

Each version in **Films & versions** has **Notes out** (editors and admins):

- **Markers…** downloads the version's notes as a marker file for the edit, at the version's own timecode (so a cut that starts at 10:00:00:00 gets markers from 10:00:00:00). Choose which notes (still to do, or all), which tracks, and whether to leave out carried notes that haven't been placed in this cut yet. Each marker carries the note's text with its track code in front, in the track's colour, and lasts as long as the note's range.
  - **Avid Media Composer**: a marker list (.txt). In the Markers window, Import Markers. Markers go on V1.
  - **DaVinci Resolve**: a marker EDL (.edl). Timeline → Import → Timeline Markers from EDL.
  - **Premiere Pro (or Resolve)**: an FCP7 XML (.xml). It imports as a new sequence that carries the markers; copy them to your cut or use it as a reference.
- **Post to Frame.io…** (only on a version linked to a Frame.io file) adds the notes as comments on that file, at their timecode, as the Adobe account signed in under Settings. Notes that came from that file's own comments are skipped, and each note is posted only once, so you can post again after adding notes and only the new ones go up. Notes carried from earlier versions can be included. Optionally each comment also carries the note's status and its replies (Frame.io's API has no way to post replies as real replies). Before posting the rest, the first comment is read back to check it landed at the right timecode; if it didn't (usually a wrong start timecode), it's removed and nothing else is posted. Syncing the version later brings back replies and "complete" ticks made in Frame.io on those comments, without duplicating the notes.

Keyboard: **space** play/pause at the speed chosen in the speed menu · **J / K / L** shuttle like an edit suite: **L** plays forward and each press goes faster (1×, 2×, 4×, 8×), **J** does the same backwards, **K** stops; hold **K** and tap **J** or **L** to step one frame · **←/→** one frame · **shift+←/→** one second · **N / P** next or previous note · **M** add a note at the playhead · **O** end the current note at the playhead · **+ / −** or **⌘/Ctrl + / −** zoom the timeline · **0** or **⌘/Ctrl 0** whole cut · **T** switch between notes and transcript.

The counts in the header (**open**, **addressed**, **to discuss**, **done**) are filters: click one to show only those notes, click it again to show all. Carried notes not yet placed in this cut get their own button above the notes list (editors only), which shows just those.

**Version colours.** As script pages are coloured by draft, each version has a revision colour (white, blue, pink, yellow, green, goldenrod, buff, salmon, cherry, tan). A new cut takes the next colour along; change it in **Films & versions → the version → Settings**, or pick it when adding the cut. The open version's colour runs along the top of the window and sits beside the version picker. Notes carried from an earlier version show that version's colour swatch in the list and on the note, the note's history shows each version's colour, and lines changed in this cut's transcript get a revision mark (*) in this version's colour.

**Views.** Three views, switched at the top or with **1**, **2**, **3**. Each keeps its own layout (picture height and sidebar width) in this browser:

- **Review**: the notes list, the note under the picture and its status buttons: for working through the comments.
- **Script**: the Transcript tab in Script view, with the transcript, its notes and the new wording side by side: for scripting and versioning.
- **Playback**: no sidebar or note card; the picture as big as it goes, with the notes shown on it as they come up, and the timeline below: for watching the cut with its timed comments.

The note under the picture keeps its actions at the bottom of the card, always in view: a reply box, **Reply**, and the everyday statuses **Addressed**, **Discuss** and **Won't do**; **More** holds Approve, Cut from film, Superseded and Reopen. Keys: **A**, **D**, **W** set those three on the current note, **R** jumps to the reply box (Ctrl+Enter sends), and **↑ ↓** move through the notes list. Every status change can be undone from the message that appears.

Playback speed and Frame.io quality are in the small speed menu in the transport (it shows the current speed). **Tracks…** sits next to **Add note here**. The **Film** and **Version** pickers at the top are labelled, and film and version setup is in the pickers themselves (editors and admins): the film menu ends with **New film…** and **Rename or manage films…**, and the version menu with **New version…**, **Manage versions…** and, for a version linked to Frame.io, **Sync comments from Frame.io** (with when it last synced). Wherever this guide says **Films & versions**, that's the Manage choice in those menus.

**Sharing with someone outside the team.** **Share** in the top bar (editors and admins) makes a link to the version you're on, for a producer, a broadcaster or a composer. Choose what they see (**Review**, **Script** or **Playback**), whether they can add notes and replies, whether they can suggest script changes (saved as drafts, under their name, for an editor to approve), whether they can also open the film's **earlier versions** (the ones made before this one; never later ones), and how long the link works (7 days, 30 days, or until you turn it off), then **Make a link and copy it**. Whoever opens it types their name, no account needed, and sees only that version, in that view. Every note and reply they add shows their name with a **guest** chip. They can reword their own notes but can't change statuses, see other versions, or reach anything else. The Share dialog lists the links to the cut, how often each was opened and who added notes; **Turn off…** ends a link straight away (their notes stay). Click outside the Share box to close it.

**Layout.** The screen keeps its shape while you work: the picture, timeline and lists stay put whatever note is showing, and a long note scrolls inside its own card. Drag the handle under the picture to make it bigger or smaller, and the line between the player and the sidebar to widen or narrow the sidebar (the 3-column script view remembers its own width). Double-click a handle to go back to the default. Sizes are remembered in each browser. The handles also work from the keyboard: Tab to one, then use the arrow keys (Shift for bigger steps).

**Help:** your name's menu → **Help and shortcuts** (or press **?**) opens a short guide to the keys and the main tasks, showing only what your role can do.

### People and roles

Open your name's menu (top right). **Your account and password** changes your name, email and password; changing the password signs you out everywhere else.

Admins also see **People**:

| Role | Can |
| --- | --- |
| Admin | Everything, plus People, Settings and connections, and deleting versions |
| Editor | Notes and statuses, versions, imports, edits, transcripts, tracks, approving script lines |
| Reviewer | Add notes and replies, suggest script wording as drafts. Can't change statuses or approve |
| Viewer | Watch and read only |

- **Invite:** enter a name, email and role, then **Invite**. With email set up (below), the invite is emailed from your address (for example hello@previouslynoted.com), with you as the reply-to. Without it, copy the link or press **Email it** to open a message in your own mail app. The link works once, for 7 days.
- **Forgotten password:** **Email a reset link** (or **Reset link**, without email set up) on their row makes a one-time link that works for 48 hours. The old password keeps working until they use it.
- **Locked out:** after 5 wrong passwords an account waits 10 minutes. **Unlock** lets them try straight away.
- **Turn off** signs someone out at once and stops them signing in; their notes and history stay. **Remove** deletes an invite or a turned-off account. There's always at least one active admin.
- A role change applies on the server straight away; their buttons update when they reload the page.

### Edits: what to export

| Editor | Export | File |
|---|---|---|
| Premiere Pro | File → Export → Final Cut Pro XML | `.xml` |
| DaVinci Resolve | File → Export → Timeline: FCP 7 XML, or OpenTimelineIO | `.xml` / `.otio` |
| Avid Media Composer | Output → List Tool: CMX 3600, with clip names in comments | `.edl` |
| Avid (AAF) | Convert to `.otio` first, see below | `.otio` |

Export the whole sequence that matches the uploaded video, with all video tracks (and the VO track if you have one, so narration notes can follow their clip). Use the same kind of export for every version of a film.

**Start timecode.** The app works out where the video starts (sequences usually start at 01:00:00:00). If the uploaded video starts somewhere else, for example a part of a longer sequence, set **Start TC** on the version in Films & versions.

**Avid AAF → .otio.** The server can't read AAF. On a Mac or PC with Python 3, once:

```
pip install opentimelineio otio-aaf-adapter
```

then for each cut:

```
otioconvert -i "RFTA Part 1.aaf" -o "RFTA Part 1.otio"
```

and import the `.otio`. (An EDL from Avid works too and needs no conversion.)

### How carried notes are placed

When a version carried from an earlier one has an edit or transcript, and so does the earlier one, the app places every carried note that nobody has placed by hand:

- **Picture notes:** the shot on screen at the note (top layer first) and the exact source frame are found in the new cut. Same shot, same frame: placed.
- **Narration notes (N):** the transcript line at the note (or the line starting within 3 seconds) is found in the new transcript; failing that, the VO clip on an audio track; failing that, the picture.
- **Flagged for a look:** *shot removed* (it sits just after where the shot was; often already addressed), *trimmed* (the exact frame was cut, it sits on the nearest surviving frame), *shot used twice* (it picks the likelier use), *line reworded*, *line gone*. These show under **Needs placing or checking**, with **Pin to playhead**, **Position is right**, and for removed shots or lines, **Mark addressed**.

Import order doesn't matter: placement runs again whenever an edit or transcript is added to either version. **Place again** in Films & versions re-runs it. Notes someone has pinned are never moved.

### Placing notes by matching pictures (no edit file needed)

When there's no edit file, **Films & versions → Placement → Match pictures…** compares the pictures of the two cuts and places the carried notes.

1. For each of the two versions, choose the video: the one already open in the player is used automatically; otherwise **Choose file…** (a download or proxy of that version), or **Use Frame.io** for a linked version.
2. Press **Match pictures**. Each video is read once, in your browser, at about 5× real time (a 45-minute cut takes about 9 minutes; keep the tab in front while it reads). The video is never uploaded; only a fingerprint (about 1.4 MB per 10 minutes) is saved, so next time that version doesn't need reading again. Fingerprints made before 0.7.6 are read again once.
3. **Shot by shot.** Cuts are found in both versions, and each shot of the earlier cut is lined up with the shot it became. The comparison ignores brightness and contrast (so regrades match), follows movement in the shot, and compares what moves within it, which tells apart different bites of the same interview. Zooms and moves are tried for reframed shots, and black bars (letterbox or pillarbox) are left out.
4. **Context, like an editor would.** Clear matches are placed first; then the shots either side are used: a shot that changed (new VFX, replaced, heavily regraded) still goes in its place when its neighbours didn't move. Shots used twice, sections that moved, trims at either end, and a hidden cut between two takes of the same set-up are handled.
5. **To the frame.** With both videos chosen, the exact cut frames are found and the frames around each note are lined up, so notes land on the exact frame. This takes about a minute per five minutes of cut with many notes. Without both videos, notes land within a few frames.
6. Only shots that changed beyond recognition go to the optional **AI step**: an image model (CLIP, run in your browser; about 90 MB to download the first time, then cached) compares them with the shots of the new cut nothing else claimed, preferring the stretch between their neighbours.

Each note gets a label:

- no label: placed by the picture, sure.
- **likely**: placed by picture and position, but another shot looks much the same (typically another bite of the same interview). Check it.
- **changed shot**: the shot's picture changed; the note sits in the same place in the shot, between the same neighbours.
- **shot used twice**: the same picture appears more than once in the new cut.
- **trimmed**: the note's moment was trimmed out; it sits on the nearest frame still in the cut.
- **shot removed**: not found in the new cut, so it may already be addressed.

What it doesn't change: notes someone pinned by hand, notes from the new cut's own comments, and narration notes the transcript already placed. Where the edit file already placed a note, the edit file wins, and the note is flagged if the pictures disagree.

Limits: a held picture (a title, a still) only has its look and its cuts to go on, so a note on a still is placed by where the shot starts or ends. Two different takes of the same interview set-up can look alike; they're labelled **likely** unless the shots around them settle it. A shot whose speed was changed is placed within a few frames. Frame.io's stream only works if Frame.io allows its pictures to be read in the browser; if it doesn't, the app says so, and a downloaded file works instead.

### Transcripts

Accepted: `.srt`, `.vtt`, and text transcripts with a timecode at the start of each line or block (Premiere's transcript export, `[00:01:22]` style, or Otter/Descript's "Speaker  0:05" style). Transcripts that start at 01:00:00:00 are shifted to the start of the cut automatically. Each upload is kept; the newest one is used for placement, and older ones can be viewed or deleted from the Transcript tab.

### On a phone or tablet

In a narrow window the screen becomes one column for live review: the picture and the play controls stay pinned at the top while you scroll, the note playing now sits right under them, then the notes list (which follows playback inside its own box, so the page never jumps), then the timeline.

### Moving the playhead and note lengths

- **Scrub:** drag along the ruler at the top of the timeline (or grab the playhead itself) to move through the cut. Click anywhere on the timeline to jump; drag empty space to move the zoomed view along.
- **Note lengths:** a note is a single moment unless it has an out-point. Single moments are drawn as a tick with the note's text beside it; the text just runs until the next note and doesn't mean anything. A note with an out-point is drawn as a bar from its start to its end, and stays on screen under the picture until the playhead passes its end.
- **Setting the end (editors and admins):** drag the handle on a note's right edge (hover a note to see it; on a single moment it sits just after the tick), or play to where it should end and press **O**, or use **End at the playhead** on the note. **Remove the end** turns it back into a single moment; dragging the handle back before the start does the same. Every change offers Undo.
- **From Frame.io:** range comments (a comment made over a selection of time) come in with their out-point, from both the CSV export and comment sync. Out-points keep their length when a note is carried and placed in the next cut, and appear in the CSV export as "00:01:00:00 to 00:01:05:12".

### Tracks

- **Show and hide:** click a track's chip (above the notes list) or its letter on the timeline. A hidden track's letter stays, faded, on a thin row; its notes leave the list and the timeline. **Alt-click** shows only that track; Alt-click again shows all. This is your own view; it doesn't change anyone else's.
- **Move a note:** drag its marker up or down on the timeline into another lane (zoom in first if notes are grouped), or pick a track from the menu on the note. A toast offers **Undo**.
- **Add or edit tracks:** **Tracks…** next to **Add note here** (or **+** under the timeline letters). Each track has a short code, a name, a colour, and how it's placed in a new cut: *by picture* (follows the shot) or *by transcript* (follows the narration line, like N). Tracks are shared by everyone on the film.
- **Codes in comments:** a comment that starts with a track's code ("M: swap this cue") lands on that track when imported, synced from Frame.io, or typed in the app.
- **Deleting** a track asks where its notes should go.
- **Tracks from your own codes:** if comments start with codes (numbered items like `ARCH-001 : …`, `CHART-002: …`, `VFX-001`, or a code with a colon or on its own, like `CG`), importing or syncing them offers to make a track for each. You can rename them, untick any you don't want, and remove the starter E, N and A tracks if nothing uses them. The notes move onto their tracks and keep their number in the text; later comments with those codes land on their track automatically. For notes already in the app, the same offer appears above the notes list, or use **Manage → Tracks… → Find tracks in the notes**. Codes can be up to 8 letters or digits.

### Script view

Open the **Transcript** tab and press **Script view**. The sidebar widens; the video and timeline stay on the left and keep playing in sync.

- **Now in this cut:** the transcript line. Click it to jump there.
- **Notes:** the notes on that line. Type in **+ Note on this line** to add one (it goes on the narration track); it's a normal note with replies and statuses.
- **New script:** **Rewrite** a line (saved as a *Draft*), **Approve** it when it's final, **Cross out** it, or add **+ Line after**. **Delete line** removes a line you added (draft or approved). Every change is kept under **History**, with who and when, and any earlier wording can be brought back. Ctrl+Enter saves a draft; Ctrl+Shift+Enter saves it approved.
- **Script track on the timeline (TX):** once a version has a transcript, the timeline gets a **TX** track above the note tracks with every line of the new script at its time: the transcript's lines on the top row, lines you add with **+ Line after** on a second row under them (a new line starts where the line before it ends and lasts as long as its words take to say). Drafts are outlined in amber, approved lines in green, crossed-out lines hatched. Click a line to go to it. Drag a line to move it, or drag its left or right edge to change when it starts or how long it lasts (editors, reviewers, and guests whose share link allows script changes); every change offers Undo. The new timing is what the script downloads show. Click **TX** to fold the track away.
- **Crossing out from the transcript (editors and admins):** hover a line in the Transcript tab and press **✕** (or click the line and press **X** or **Delete**) to cross it out of the new script; **↺** (or X again) brings it back, and the message that appears has Undo. Crossed-out lines stay in the list struck through; untick **Show crossed-out lines** to hide them. Reviewers, and guests whose share link allows script changes, can take back their own draft suggestions (**Withdraw**, or **Delete line** for a line they added). Those guests can also cross lines out (not approved ones) and restore the lines they crossed out; the line shows who crossed it out.
- **Download script…** (in the Transcript tab) asks what you want:
  - **Original script:** the transcript as it is, line by line with timecodes.
  - **Original with the new script in place:** the whole script, with new wording replacing the old line on the same row, added lines where they go, and crossed-out lines left out (or kept and marked, if you tick that).
  - **Only the script changes:** just the rewritten, added and crossed-out lines, with in and out timecodes, for the VO session.

  Then choose the file:
  - **PDF** (the default): changed lines highlighted, rewritten in yellow with what it was underneath, new lines in green, crossed-out lines grey and struck through, each labelled in the margin (and marked *draft* if it isn't approved yet).
  - **Spreadsheet (CSV):** the first column says what changed (REWRITTEN, NEW, CROSSED OUT), the line itself starts with ★, + or ✕, and the old wording is in *Was*. Spreadsheets can't carry colours, hence the marks.
  - **Plain text:** changed lines marked `*`, new lines `+`.

  And whether drafts count, or only approved changes. Timecodes follow the TX track, so moved lines download at their new times.
- **Recorded:** when the next cut (carried from this one) has a transcript, lines whose new wording appears in it are marked **Recorded in …**.

### Start timecode

Frame.io and most edit suites show timecode from the file's own start, often 01:00:00:00 or 10:00:00:00. Each version has a start timecode (Films & versions → **Timecode**), and every timecode in the app, the CSV export and the recording script is shown from it, so they match Frame.io.

- Importing a Frame.io comment export sets it automatically (the export has both the frame number and the timecode). Syncing comments from Frame.io works it out from the comments: if the earliest is an hour or more in, the version is taken to start on that hour.
- If notes came in before this was set and now sit an hour or more past the end of the video, the app notices and offers **Start this version at 10:00:00:00**, which also moves the notes back into place. **Change** under Timecode does the same by hand (tick *Also move this version's notes*).

### Frame rate

Each version has a frame rate (Films & versions). It defaults to 24. If your file is really 23.976 or 29.97, notes drift later in the film; the app notices after about ten seconds of playback and offers to switch. You can also set it by hand. The timecode base (24, 25, 30…) is read from the Frame.io export.

### Video formats

Browsers play H.264 MP4 (Frame.io's downloads and proxies are fine). ProRes and DNxHD won't play in a browser; use the Frame.io proxy instead.

## Email (invites and password resets)

Invites and reset links can go out from your own mailbox, such as hello@previouslynoted.com. Sending through the domain's own mail server, with the domain's email authentication in place, is what keeps them out of spam.

**1. Set it up in the app.** Your name's menu → **Settings and connections** → **Email**:

| | cPanel mailbox (Stellar Plus) | Namecheap Private Email |
| --- | --- | --- |
| Server | `mail.previouslynoted.com` | `mail.privateemail.com` |
| Security / port | SSL/TLS, 465 | SSL/TLS, 465 |
| Username | the full address, `hello@previouslynoted.com` | the full address |
| Password | the mailbox's password | the mailbox's password |

Press **Save and send a test**. The app connects, sends you a test email, and only saves the settings if that worked. The password stays on the server and is never sent to browsers.

If it says the certificate didn't match, use the server name shown in cPanel → **Email Accounts** → **Connect Devices** (something like `server123.web-hosting.com`).

**2. Make sure the domain is authenticated.** This is what really decides spam or inbox:

- **cPanel mailbox:** cPanel → **Email Deliverability**. Both **SPF** and **DKIM** should say *Valid*. If not, press **Repair**. If the domain's DNS is managed at Namecheap (Domain List → Manage → **Advanced DNS**) rather than by the hosting, cPanel shows the exact records to add there.
- **Private Email:** Namecheap adds the SPF and DKIM records automatically when the domain uses Namecheap's DNS; check Domain List → Manage → **Advanced DNS**.
- **DMARC** (both): add a TXT record with host `_dmarc` and value `v=DMARC1; p=none; rua=mailto:hello@previouslynoted.com`. Gmail and Yahoo expect one.

Then send yourself a test at a Gmail address and use *Show original*: SPF, DKIM and DMARC should all say **PASS**.

Every invite and reset link is still shown to the admin too, so if an email doesn't arrive you can copy the link and send it another way.

## Brand

The icon is a note carried forward: the outline behind is the previous cut, the amber note (the Edit track's colour) sits on the new one. It appears in the header, on the sign-in pages, in the installer, as the browser-tab icon (`favicon.ico`, plus SVG) and as the home-screen icon when the app is added to a phone. The files are in `assets/icons/`: graphite `#1C2025`, paper `#EBE6DC`, amber `#E6A94F`, previous-cut grey `#7B828B`. Below 48 px it uses a simplified drawing (`icon-small.svg`). On graphite pages the tile steps up to `#2C323A` so its edge shows.

## Backups

Everything lives in `data/notes.sqlite`. Download it now and then from File Manager, or include it in cPanel → **Backup**. To restore, put the file back in `data/`.

`data/config.php` holds the app name; passwords are stored only as hashes in the database. `data/connections.php` holds the Frame.io and ElevenLabs credentials (never sent to browsers).

**Every admin locked out?** Rename `data/config.php` in File Manager and open the site. It shows the setup page: enter your existing email and a new password and you're an admin again (a new email creates a new admin). Notes and other accounts are kept. Anyone who can reach File Manager can do this, which is why it's the recovery route.

## Security notes

- Individual accounts with roles, enforced on the server for every action (hidden buttons are just convenience). Passwords are hashed with PHP's `password_hash`; invite and reset links are stored only as hashes and work once.
- Share links are long random addresses for one version. A guest can read that version's notes (and, if allowed, add notes and replies); the server checks every request against the link's version and refuses everything else. Turning a link off, or its end date, stops it at once.
- Each account locks for 10 minutes after 5 wrong passwords, and repeated attempts from one browser are slowed. Sessions last 14 days; changing a password, a reset, or turning an account off ends that person's other sessions straight away.
- Only admins can connect Frame.io or ElevenLabs or see their settings.
- `data/` and `lib/` are blocked from the web by `.htaccess`. The installer tests this on your server. Pages ask search engines not to index them.
- Comment text is all the server stores. Video never leaves the reviewer's machine unless you paste a link.

## Files

```
index.php        sign-in, setup and the app page
oauth.php        where Adobe returns after "Sign in with Adobe"
api.php          JSON endpoints used by the page
lib/             PHP helpers: Frame.io CSV import, edit readers (OTIO, FCP7 XML, EDL), transcripts, placement,
                 Frame.io and ElevenLabs connections
assets/          app.js and app.css
data/            database and config (created on setup, never public)
.htaccess        access rules
.user.ini        upload size (edit files can be tens of MB) and memory
```

## Next steps this is designed for

- Frame.io webhooks: new comments and new uploads appear without syncing.
- Writing back to Frame.io: mark comments complete, post replies, carry notes onto the new file.

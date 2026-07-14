Overview
Video editing requires substantial amount of learning before becoming productive. Yet, in most cases, video editing involves only a few basic tasks, namely, acquiring video clips as raw materials in a project, arranging them, adding text to each video to describe what it is, adding transitions between video clips, adding a sound track, adding a cover and an ending. The end result is a single MP4 file. When playing it, the edited video shows the cover, and then plays video clips in sequence, displays the text in each video clip, transitions from one clip to another with varying styles, accompanies the show with the music, audio, or song added to the sound track during the editing, and ends the show with the ending such as "Thank for watching!", plus credits for the author, contributors, copyright info and disclaims when applicable.
AI agent technology is very matured these days. An AI agent can certainly perform those tasks more efficiently and accurately for a user, particularly for those without video editing knowledge and training. In this context, a simple-to-use mobile app called Blue Sky Video Editor (BSVE) is developed to help a user to make an edited video per his or her liking easily and quickly with AI assistance.  

BSVE does the following. 
1. Ask user to upload videos to the server (datacommlab.com)
2. Ask user to add text to each video, and position requirement, either top, bottom, left, or right
3. Ask user to specify transition style, default to the basic wipe from left to right transition
4. Ask user to add sound track, either by uploading a music piece, a song, or an audio recording. To upload the sound track material, accept commonly used audio and/or video file types. 
5. When user click Done, the uploads and instructions go to the Ubuntu Linux server. 
6. Video editing takes place on the server. AI agent generates the edited video in MP4 format, creates its URL, and sends the link back to user for viewing. 

Other information
Development environment includes two parts with a pipeline
Part One - Developer
Windows WSL Ubuntu at tshen@HOUSTON
~/android/blue-sky-video-editor$

Part Two - Production
Ubuntu VSP datacommlab.com at tshen@datacommlab.com
~/android/blue-sky-video-editor$

Pipeline
Push 
update.sh at 
tshen@HOUSTON:~/android/blue-sky-video-editor/update.sh

Pull 
tshen@datacommlab.com:~/android/blue-sky-video-editor/deploy.sh

GitHub repo
tonydshen/blue-sky-video-editor


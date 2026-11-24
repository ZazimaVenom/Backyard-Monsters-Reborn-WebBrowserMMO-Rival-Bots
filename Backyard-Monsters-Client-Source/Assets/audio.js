import { voicedSpecies } from './combat-performances.js?v=0.13.0';
/** Gesture-unlocked, decoded audio with seamless loops and bounded effect voices. */
export class GameAudio {
  constructor(){
    let enabled=false,volume=.24;
    try{enabled=localStorage.getItem('bmr-audio')==='on';const saved=Number(localStorage.getItem('bmr-volume'));if(localStorage.getItem('bmr-volume')!==null&&Number.isFinite(saved))volume=Math.min(.6,Math.max(0,saved))}catch{}
    this.enabled=enabled;this.volume=volume;this.track='theme';this.context=null;this.music=null;this.buffers=new Map();this.voices=new Set();this.creatureSources=new Set();this.creatureGeneration=0;this.musicSources=new Set();this.last={};this.generation=0;
  }
  async unlock(){
    if(!this.enabled)return;
    const Context=window.AudioContext||window.webkitAudioContext;if(!Context)return;
    if(!this.context)this.context=new Context();
    try{if(this.context.state==='suspended')await this.context.resume();await this.playMusic(this.track)}catch{}
  }
  async toggle(){
    this.enabled=!this.enabled;try{localStorage.setItem('bmr-audio',this.enabled?'on':'off')}catch{}
    if(this.enabled)await this.unlock();else{++this.generation;++this.creatureGeneration;this.creatureSources.clear();this.stopMusic();for(const source of this.musicSources){try{source.stop()}catch{}}this.musicSources.clear();for(const voice of this.voices){try{voice.stop()}catch{}}this.voices.clear()}
    return this.enabled;
  }
  async buffer(name,extension,folder='audio'){
    const key=folder+'/'+name+'.'+extension;if(this.buffers.has(key))return this.buffers.get(key);
    const pending=fetch('assets/'+key).then(r=>{if(!r.ok)throw new Error('Audio unavailable');return r.arrayBuffer()}).then(bytes=>this.context.decodeAudioData(bytes));
    this.buffers.set(key,pending);try{return await pending}catch(error){this.buffers.delete(key);throw error}
  }
  stopMusic(){if(!this.music)return;const music=this.music;this.music=null;try{music.source.stop()}catch{}music.gain.disconnect()}
  async playMusic(name='theme'){
    this.track=name;if(!this.enabled||!this.context||this.context.state!=='running')return;
    if(this.music?.name===name)return;
    const generation=++this.generation;
    try{
      const buffer=await this.buffer(name,'ogg');if(!this.enabled||generation!==this.generation)return;
      const ctx=this.context,source=ctx.createBufferSource(),gain=ctx.createGain(),old=this.music;
      source.buffer=buffer;source.loop=true;source.connect(gain);gain.connect(ctx.destination);gain.gain.setValueAtTime(0,ctx.currentTime);gain.gain.linearRampToValueAtTime(this.volume,ctx.currentTime+.6);source.start();this.musicSources.add(source);source.onended=()=>{this.musicSources.delete(source);gain.disconnect()};this.music={source,gain,name};
      if(old){old.gain.gain.cancelScheduledValues(ctx.currentTime);old.gain.gain.setValueAtTime(old.gain.gain.value,ctx.currentTime);old.gain.gain.linearRampToValueAtTime(0,ctx.currentTime+.6);try{old.source.stop(ctx.currentTime+.62)}catch{}}
    }catch{}
  }
  creature(type,cue) {
    if (!this.enabled || !this.context || this.context.state!=='running' || this.volume<=0 ||
        !voicedSpecies.has(type) || !['call','defeat'].includes(cue)) return;
    const id=type+'-'+cue, key='creature/'+id, now=performance.now();
    // Whole buckets and simultaneous tower volleys should sound like a group, not a stack
    // of identical samples. A late decode from a suspended tab must also stay silent.
    if (now-(this.last[key]??-Infinity)<330 || this.creatureSources.size>=5) return;
    this.last[key]=now;
    const generation=this.creatureGeneration;
    this.buffer(id,'ogg','voices').then(buffer=>{
      if (!this.enabled || !this.volume || this.creatureGeneration!==generation || this.context.state!=='running' ||
          this.creatureSources.size>=5 || this.voices.size>=24 || performance.now()-now>800 ||
          (typeof document!=='undefined' && document.hidden)) return;
      const source=this.context.createBufferSource(), gain=this.context.createGain();
      source.buffer=buffer;gain.gain.value=Math.min(.31,this.volume*(cue==='defeat' ? .91 : 1.06));
      source.connect(gain);gain.connect(this.context.destination);
      this.voices.add(source);this.creatureSources.add(source);
      source.onended=()=>{this.voices.delete(source);this.creatureSources.delete(source);gain.disconnect();};
      source.start();
    }).catch(()=>{});
  }
  effect(name){
    if(!this.enabled||!this.context||this.context.state!=='running')return;
    const allowed=['build','collect','hatch','hit','shot','victory','defeat','explode','heal','burrow','laser','fling','champion','champion-retreat','feed','evolve','research','catapult','catapult-impact','emergence-quake','boost','blink','venom','aerial','heavytrap','baiter','siren','sack','quest'];if(!allowed.includes(name))return;
    const now=performance.now(),quiet=['hit','shot','heal','laser','burrow','explode','aerial','heavytrap'].includes(name),interval=quiet?160:60;
    if(now-(this.last[name]??-Infinity)<interval)return;this.last[name]=now;
    this.buffer(name,'wav').then(buffer=>{
      if(!this.enabled||this.context.state!=='running'||this.voices.size>=24)return;
      const source=this.context.createBufferSource(),gain=this.context.createGain();source.buffer=buffer;gain.gain.value=quiet?.13:.45;source.connect(gain);gain.connect(this.context.destination);this.voices.add(source);source.onended=()=>{this.voices.delete(source);gain.disconnect()};source.start();
    }).catch(()=>{});
  }
  setVolume(value){
    this.volume=Math.min(.6,Math.max(0,Number(value)||0));try{localStorage.setItem('bmr-volume',String(this.volume))}catch{}
    if(this.music){const time=this.context.currentTime;this.music.gain.gain.cancelScheduledValues(time);this.music.gain.gain.setTargetAtTime(this.volume,time,.05)}
  }
}

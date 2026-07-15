import AsyncStorage from "@react-native-async-storage/async-storage";
import * as DocumentPicker from "expo-document-picker";
import * as ImagePicker from "expo-image-picker";
import React, { useEffect, useState } from "react";
import {
  ActivityIndicator,
  Alert,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  Text,
  TextInput,
  TouchableOpacity,
  View,
} from "react-native";

const UPLOAD_URL = "https://datacommlab.com/scripts/bsve_upload.php";

// --- Types ---
type Position = "top" | "bottom" | "left" | "right";

interface Clip {
  uri: string;
  name: string;
  text: string;
  position: Position;
}

interface SoundTrack {
  uri: string;
  name: string;
  mimeType: string;
}

interface Cover {
  title: string;
  subtitle: string;
}

interface Ending {
  message: string;
  credits: string;
  copyright: string;
  disclaimer: string;
}

interface Profile {
  fname: string;
  lname: string;
  email: string;
  phone: string;
}

// The transition styles the server accepts. `wipeleft` is the documented default.
const TRANSITIONS: { key: string; label: string }[] = [
  { key: "wipeleft", label: "Wipe →" },
  { key: "wiperight", label: "Wipe ←" },
  { key: "wipeup", label: "Wipe ↑" },
  { key: "wipedown", label: "Wipe ↓" },
  { key: "fade", label: "Fade" },
  { key: "dissolve", label: "Dissolve" },
  { key: "slideleft", label: "Slide →" },
  { key: "circleopen", label: "Circle" },
];

const POSITIONS: Position[] = ["top", "bottom", "left", "right"];

const EMPTY_ENDING: Ending = {
  message: "Thanks for watching!",
  credits: "",
  copyright: "",
  disclaimer: "",
};

export default function BlueSkyVideoEditor() {
  const [tab, setTab] = useState<"Clips" | "Sound" | "Cover" | "Profile">(
    "Clips",
  );
  const [projectTitle, setProjectTitle] = useState("");
  const [clips, setClips] = useState<Clip[]>([]);
  const [transition, setTransition] = useState("wipeleft");
  const [soundTrack, setSoundTrack] = useState<SoundTrack | null>(null);
  const [cover, setCover] = useState<Cover>({ title: "", subtitle: "" });
  const [ending, setEnding] = useState<Ending>(EMPTY_ENDING);
  const [profile, setProfile] = useState<Profile>({
    fname: "",
    lname: "",
    email: "",
    phone: "",
  });
  const [uploading, setUploading] = useState(false);
  const [loaded, setLoaded] = useState(false);

  // Restore the profile and any work in progress on startup.
  useEffect(() => {
    (async () => {
      const savedProfile = await AsyncStorage.getItem("@user_profile");
      const savedDraft = await AsyncStorage.getItem("@bsve_draft");
      if (savedProfile) setProfile(JSON.parse(savedProfile));
      if (savedDraft) {
        const d = JSON.parse(savedDraft);
        setProjectTitle(d.projectTitle ?? "");
        setClips(d.clips ?? []);
        setTransition(d.transition ?? "wipeleft");
        setSoundTrack(d.soundTrack ?? null);
        setCover(d.cover ?? { title: "", subtitle: "" });
        setEnding(d.ending ?? EMPTY_ENDING);
      }
      setLoaded(true);
    })();
  }, []);

  // Persist the draft so the user never loses work between app launches.
  useEffect(() => {
    if (!loaded) return;
    AsyncStorage.setItem(
      "@bsve_draft",
      JSON.stringify({
        projectTitle,
        clips,
        transition,
        soundTrack,
        cover,
        ending,
      }),
    );
  }, [loaded, projectTitle, clips, transition, soundTrack, cover, ending]);

  const saveProfile = async (next: Profile) => {
    setProfile(next);
    await AsyncStorage.setItem("@user_profile", JSON.stringify(next));
  };

  // --- Pickers ---
  const addClips = async () => {
    const perm = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!perm.granted) {
      Alert.alert(
        "Permission needed",
        "Allow access to your videos so they can be added to the project.",
      );
      return;
    }
    const result = await ImagePicker.launchImageLibraryAsync({
      mediaTypes: ["videos"],
      allowsMultipleSelection: true,
      quality: 1,
    });
    if (result.canceled) return;

    const added: Clip[] = result.assets.map((a, i) => ({
      uri: a.uri,
      name: a.fileName ?? `clip_${Date.now()}_${i}.mp4`,
      text: "",
      position: "bottom",
    }));
    setClips((prev) => [...prev, ...added]);
  };

  const addSoundTrack = async () => {
    // Accept common audio types, and video files the user wants the audio from.
    const result = await DocumentPicker.getDocumentAsync({
      type: ["audio/*", "video/*"],
      copyToCacheDirectory: true,
    });
    if (result.canceled) return;
    const a = result.assets[0];
    setSoundTrack({
      uri: a.uri,
      name: a.name,
      mimeType: a.mimeType ?? "application/octet-stream",
    });
  };

  const updateClip = (index: number, patch: Partial<Clip>) =>
    setClips((prev) =>
      prev.map((c, i) => (i === index ? { ...c, ...patch } : c)),
    );

  const removeClip = (index: number) =>
    setClips((prev) => prev.filter((_, i) => i !== index));

  const moveClip = (index: number, delta: number) =>
    setClips((prev) => {
      const target = index + delta;
      if (target < 0 || target >= prev.length) return prev;
      const next = [...prev];
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });

  // --- Submit ---
  const handleDone = async () => {
    if (clips.length === 0) {
      Alert.alert("No videos yet", "Add at least one video clip first.");
      return;
    }
    if (!profile.fname.trim() || !profile.email.trim()) {
      Alert.alert(
        "Profile incomplete",
        "Enter your first name and email in the Profile tab. Your finished video is emailed to you.",
      );
      setTab("Profile");
      return;
    }

    setUploading(true);
    try {
      const form = new FormData();
      form.append("project_title", projectTitle);
      form.append("transition", transition);

      // Cover and ending default to sensible values so the user can just accept them.
      form.append("cover_title", cover.title.trim() || projectTitle);
      form.append("cover_subtitle", cover.subtitle);
      form.append("ending_message", ending.message.trim() || "Thanks for watching!");
      form.append(
        "ending_credits",
        ending.credits.trim() || `${profile.fname} ${profile.lname}`.trim(),
      );
      form.append(
        "ending_copyright",
        ending.copyright.trim() ||
          `© ${new Date().getFullYear()} ${profile.fname} ${profile.lname}`.trim(),
      );
      form.append("ending_disclaimer", ending.disclaimer);

      form.append("fname", profile.fname);
      form.append("lname", profile.lname);
      form.append("email", profile.email);
      form.append("phone", profile.phone);

      form.append("clip_count", String(clips.length));
      clips.forEach((clip, i) => {
        form.append(`clip_${i}`, {
          uri: clip.uri,
          type: "video/mp4",
          name: clip.name,
        } as unknown as Blob);
        form.append(`clip_text_${i}`, clip.text);
        form.append(`clip_pos_${i}`, clip.position);
      });

      if (soundTrack) {
        form.append("soundtrack", {
          uri: soundTrack.uri,
          type: soundTrack.mimeType,
          name: soundTrack.name,
        } as unknown as Blob);
      }

      const response = await fetch(UPLOAD_URL, {
        method: "POST",
        body: form,
        headers: { "X-Requested-With": "XMLHttpRequest" },
      });
      const result = await response.json();

      if (!result.ok) throw new Error(result.error ?? "Upload failed.");

      Alert.alert(
        "Uploaded!",
        `Your video is being edited. We'll email the link to ${profile.email} when it's ready — usually within a few minutes.`,
      );
    } catch (e: unknown) {
      Alert.alert(
        "Upload failed",
        e instanceof Error ? e.message : "Something went wrong.",
      );
    } finally {
      setUploading(false);
    }
  };

  // --- Views ---
  const renderClips = () => (
    <ScrollView style={styles.content}>
      <Text style={styles.label}>Project title</Text>
      <TextInput
        style={styles.input}
        placeholder="My trip to the coast"
        value={projectTitle}
        onChangeText={setProjectTitle}
      />

      <View style={styles.sectionHeader}>
        <Text style={styles.section}>Video clips</Text>
        <TouchableOpacity style={styles.addBtn} onPress={addClips}>
          <Text style={styles.addBtnText}>+ Add videos</Text>
        </TouchableOpacity>
      </View>

      {clips.length === 0 && (
        <Text style={styles.hint}>
          Add the videos you want in your movie. They play in the order shown
          here.
        </Text>
      )}

      {clips.map((clip, i) => (
        <View key={`${clip.uri}-${i}`} style={styles.card}>
          <View style={styles.cardHeader}>
            <Text style={styles.cardTitle} numberOfLines={1}>
              {i + 1}. {clip.name}
            </Text>
            <View style={styles.row}>
              <TouchableOpacity onPress={() => moveClip(i, -1)}>
                <Text style={styles.iconBtn}>↑</Text>
              </TouchableOpacity>
              <TouchableOpacity onPress={() => moveClip(i, 1)}>
                <Text style={styles.iconBtn}>↓</Text>
              </TouchableOpacity>
              <TouchableOpacity onPress={() => removeClip(i)}>
                <Text style={[styles.iconBtn, styles.deleteBtn]}>✕</Text>
              </TouchableOpacity>
            </View>
          </View>

          <TextInput
            style={styles.input}
            placeholder="Text to show on this clip"
            value={clip.text}
            onChangeText={(text) => updateClip(i, { text })}
          />

          <Text style={styles.label}>Text position</Text>
          <View style={styles.chipRow}>
            {POSITIONS.map((pos) => (
              <TouchableOpacity
                key={pos}
                style={[
                  styles.chip,
                  clip.position === pos && styles.chipSelected,
                ]}
                onPress={() => updateClip(i, { position: pos })}
              >
                <Text
                  style={[
                    styles.chipText,
                    clip.position === pos && styles.chipTextSelected,
                  ]}
                >
                  {pos}
                </Text>
              </TouchableOpacity>
            ))}
          </View>
        </View>
      ))}

      <Text style={styles.section}>Transition between clips</Text>
      <View style={styles.chipRow}>
        {TRANSITIONS.map((t) => (
          <TouchableOpacity
            key={t.key}
            style={[styles.chip, transition === t.key && styles.chipSelected]}
            onPress={() => setTransition(t.key)}
          >
            <Text
              style={[
                styles.chipText,
                transition === t.key && styles.chipTextSelected,
              ]}
            >
              {t.label}
            </Text>
          </TouchableOpacity>
        ))}
      </View>
    </ScrollView>
  );

  const renderSound = () => (
    <ScrollView style={styles.content}>
      <Text style={styles.section}>Sound track</Text>
      <Text style={styles.hint}>
        Add music, a song, or a voice recording to play under your video. Most
        audio and video files work.
      </Text>

      <TouchableOpacity style={styles.addBtn} onPress={addSoundTrack}>
        <Text style={styles.addBtnText}>
          {soundTrack ? "+ Replace sound track" : "+ Add sound track"}
        </Text>
      </TouchableOpacity>

      {soundTrack && (
        <View style={styles.card}>
          <View style={styles.cardHeader}>
            <Text style={styles.cardTitle} numberOfLines={1}>
              ♪ {soundTrack.name}
            </Text>
            <TouchableOpacity onPress={() => setSoundTrack(null)}>
              <Text style={[styles.iconBtn, styles.deleteBtn]}>✕</Text>
            </TouchableOpacity>
          </View>
        </View>
      )}
    </ScrollView>
  );

  const renderCover = () => (
    <ScrollView style={styles.content}>
      <Text style={styles.section}>Cover</Text>
      <Text style={styles.hint}>Shown at the start of your video.</Text>

      <Text style={styles.label}>Cover title</Text>
      <TextInput
        style={styles.input}
        placeholder={projectTitle || "My video"}
        value={cover.title}
        onChangeText={(title) => setCover({ ...cover, title })}
      />

      <Text style={styles.label}>Cover subtitle</Text>
      <TextInput
        style={styles.input}
        placeholder="Summer 2026"
        value={cover.subtitle}
        onChangeText={(subtitle) => setCover({ ...cover, subtitle })}
      />

      <Text style={styles.section}>Ending</Text>
      <Text style={styles.hint}>Shown at the end of your video.</Text>

      <Text style={styles.label}>Closing message</Text>
      <TextInput
        style={styles.input}
        placeholder="Thanks for watching!"
        value={ending.message}
        onChangeText={(message) => setEnding({ ...ending, message })}
      />

      <Text style={styles.label}>Credits</Text>
      <TextInput
        style={styles.input}
        placeholder={`${profile.fname} ${profile.lname}`.trim() || "Your name"}
        value={ending.credits}
        onChangeText={(credits) => setEnding({ ...ending, credits })}
      />

      <Text style={styles.label}>Copyright</Text>
      <TextInput
        style={styles.input}
        placeholder={`© ${new Date().getFullYear()} ${profile.fname} ${profile.lname}`.trim()}
        value={ending.copyright}
        onChangeText={(copyright) => setEnding({ ...ending, copyright })}
      />

      <Text style={styles.label}>Disclaimer (optional)</Text>
      <TextInput
        style={[styles.input, styles.multiline]}
        placeholder="Any disclaimer you want to include"
        multiline
        value={ending.disclaimer}
        onChangeText={(disclaimer) => setEnding({ ...ending, disclaimer })}
      />
    </ScrollView>
  );

  const renderProfile = () => (
    <ScrollView style={styles.content}>
      <Text style={styles.section}>Your profile</Text>
      <Text style={styles.hint}>
        Your finished video link is emailed to you, so your name and email are
        required.
      </Text>

      <Text style={styles.label}>First name *</Text>
      <TextInput
        style={styles.input}
        value={profile.fname}
        onChangeText={(fname) => saveProfile({ ...profile, fname })}
      />

      <Text style={styles.label}>Last name</Text>
      <TextInput
        style={styles.input}
        value={profile.lname}
        onChangeText={(lname) => saveProfile({ ...profile, lname })}
      />

      <Text style={styles.label}>Email *</Text>
      <TextInput
        style={styles.input}
        autoCapitalize="none"
        keyboardType="email-address"
        value={profile.email}
        onChangeText={(email) => saveProfile({ ...profile, email })}
      />

      <Text style={styles.label}>Phone</Text>
      <TextInput
        style={styles.input}
        keyboardType="phone-pad"
        value={profile.phone}
        onChangeText={(phone) => saveProfile({ ...profile, phone })}
      />
    </ScrollView>
  );

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === "ios" ? "padding" : undefined}
    >
      <View style={styles.header}>
        <Text style={styles.headerTitle}>Blue Sky Video Editor</Text>
        <Text style={styles.headerSub}>
          {clips.length} clip{clips.length === 1 ? "" : "s"}
        </Text>
      </View>

      {tab === "Clips" && renderClips()}
      {tab === "Sound" && renderSound()}
      {tab === "Cover" && renderCover()}
      {tab === "Profile" && renderProfile()}

      <TouchableOpacity
        style={[styles.doneBtn, uploading && styles.doneBtnDisabled]}
        onPress={handleDone}
        disabled={uploading}
      >
        {uploading ? (
          <ActivityIndicator color="#fff" />
        ) : (
          <Text style={styles.doneBtnText}>Done — make my video</Text>
        )}
      </TouchableOpacity>

      <View style={styles.tabBar}>
        {(["Clips", "Sound", "Cover", "Profile"] as const).map((t) => (
          <TouchableOpacity
            key={t}
            style={styles.tab}
            onPress={() => setTab(t)}
          >
            <Text style={[styles.tabText, tab === t && styles.tabTextActive]}>
              {t}
            </Text>
          </TouchableOpacity>
        ))}
      </View>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1, backgroundColor: "#f5f7fa" },
  header: {
    backgroundColor: "#1976d2",
    paddingTop: 56,
    paddingBottom: 16,
    paddingHorizontal: 16,
  },
  headerTitle: { color: "#fff", fontSize: 20, fontWeight: "700" },
  headerSub: { color: "#cfe3f7", fontSize: 13, marginTop: 2 },
  content: { flex: 1, padding: 16 },
  section: {
    fontSize: 17,
    fontWeight: "700",
    color: "#123",
    marginTop: 20,
    marginBottom: 6,
  },
  sectionHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  label: { fontSize: 13, color: "#5a6672", marginTop: 10, marginBottom: 4 },
  hint: { fontSize: 13, color: "#78868f", marginBottom: 10, lineHeight: 18 },
  input: {
    backgroundColor: "#fff",
    borderWidth: 1,
    borderColor: "#dce3ea",
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: 15,
  },
  multiline: { height: 80, textAlignVertical: "top" },
  card: {
    backgroundColor: "#fff",
    borderRadius: 10,
    padding: 12,
    marginTop: 12,
    borderWidth: 1,
    borderColor: "#e6ebf0",
  },
  cardHeader: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    marginBottom: 8,
  },
  cardTitle: { flex: 1, fontWeight: "600", color: "#123", marginRight: 8 },
  row: { flexDirection: "row", alignItems: "center" },
  iconBtn: { fontSize: 18, paddingHorizontal: 8, color: "#1976d2" },
  deleteBtn: { color: "#d33" },
  addBtn: {
    backgroundColor: "#e3f0fb",
    borderRadius: 8,
    paddingVertical: 10,
    paddingHorizontal: 14,
    alignSelf: "flex-start",
    marginTop: 10,
  },
  addBtnText: { color: "#1976d2", fontWeight: "600" },
  chipRow: { flexDirection: "row", flexWrap: "wrap", marginTop: 4 },
  chip: {
    borderWidth: 1,
    borderColor: "#c8d4de",
    borderRadius: 16,
    paddingVertical: 6,
    paddingHorizontal: 12,
    marginRight: 8,
    marginBottom: 8,
    backgroundColor: "#fff",
  },
  chipSelected: { backgroundColor: "#1976d2", borderColor: "#1976d2" },
  chipText: { color: "#4a5b68", fontSize: 13 },
  chipTextSelected: { color: "#fff", fontWeight: "600" },
  doneBtn: {
    backgroundColor: "#1976d2",
    margin: 16,
    borderRadius: 10,
    paddingVertical: 15,
    alignItems: "center",
  },
  doneBtnDisabled: { backgroundColor: "#8fb8dd" },
  doneBtnText: { color: "#fff", fontSize: 16, fontWeight: "700" },
  tabBar: {
    flexDirection: "row",
    borderTopWidth: 1,
    borderTopColor: "#e0e6ec",
    backgroundColor: "#fff",
    paddingBottom: 20,
  },
  tab: { flex: 1, alignItems: "center", paddingVertical: 12 },
  tabText: { color: "#8a97a3", fontSize: 13 },
  tabTextActive: { color: "#1976d2", fontWeight: "700" },
});

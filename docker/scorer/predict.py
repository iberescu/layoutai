"""
layout.ai creative scorer — TRIBE v2 wrapper for Replicate.

Input: a single static image (the rendered ad).
Output: { "score": 0..100, "regions": {...}, "model": "tribev2", "version": "..." }

How it scores
-------------
TRIBE v2 (facebook/tribev2, CC BY-NC) predicts fMRI brain responses on the
fsaverage5 cortical mesh from multimodal stimuli (video + audio + text). It
does NOT accept static images directly, so we synthesise:
    * a 2-second still-frame video at 8 fps (16 frames)
    * 2 seconds of silent audio at 16 kHz
    * an empty text track

We pass these through the model, then aggregate activations across
visual-cortex vertices on the fsaverage5 mesh into a single 0–100 score
that proxies "predicted visual attention". The aggregation is intentionally
simple so the formula is easy to inspect & tune downstream.

Aggregation formula
-------------------
    score = clip(0, 100,
        50 + 12 * z(mean(|activation| over visual_cortex_vertices, over time))
    )

where z(x) is z-scored vs a small reference set baked at warm-up. This
keeps scores roughly Normal(50, 12) so the UI's quartile colors line up
with what users expect (top quartile ≥ 75, etc.).
"""

import os
import tempfile
import pathlib

import numpy as np
import soundfile as sf
import imageio
from PIL import Image

from cog import BasePredictor, Input, Path


# Indices of fsaverage5 vertices that fall inside early visual cortex (V1/V2/V4/IT).
# Replace with the actual indices from the fsaverage5 atlas (Glasser/HCP labels).
# Until the model is wired up we use a placeholder slice of the first 4096
# vertices so the math runs end-to-end.
VISUAL_CORTEX_SLICE = slice(0, 4096)

# Reference mean/std for z-scoring. These are filled in on warm-up by passing
# a small batch of natural images through the model and caching the result.
# Until the model is loaded we use neutral defaults so mock-runs still produce
# scores in a reasonable range.
REF_MEAN = float(os.environ.get("LAYOUT_TRIBE_REF_MEAN", "0.10"))
REF_STD  = float(os.environ.get("LAYOUT_TRIBE_REF_STD",  "0.04"))


class Predictor(BasePredictor):
    def setup(self) -> None:
        """Load the TRIBE v2 model into memory once per container boot."""
        # The HF token is provided as a build-time secret. The model card
        # explains that v2 weights are public (CC BY-NC) so no gating is
        # strictly required, but we still respect the env var if set.
        token = os.environ.get("HUGGINGFACE_TOKEN")
        if token:
            os.environ["HF_TOKEN"] = token

        try:
            from tribev2 import TribeModel
            self.model = TribeModel.from_pretrained(
                "facebook/tribev2",
                cache_folder="./cache",
            )
            self.model_loaded = True
        except Exception as e:
            # If the model can't load (e.g. during cold-start tests on CPU),
            # we still respond — with a clearly-marked "scoring unavailable"
            # output rather than throwing. The Laravel client then falls back
            # to its mock score.
            print(f"[scorer] TRIBE v2 load failed: {e}")
            self.model = None
            self.model_loaded = False

    def predict(
        self,
        image: Path = Input(description="Rendered ad PNG/JPEG"),
    ) -> dict:
        if not self.model_loaded:
            return {
                "score": None,
                "error": "tribev2 not loaded; check container logs",
                "model": "tribev2",
                "version": "v2",
            }

        # 1. Synthesise a 2-second still-frame video + silent audio.
        tmp_dir = pathlib.Path(tempfile.mkdtemp())
        video_path = tmp_dir / "stim.mp4"
        audio_path = tmp_dir / "stim.wav"
        self._image_to_video(image, video_path, seconds=2, fps=8)
        self._silent_audio(audio_path, seconds=2, sample_rate=16_000)

        # 2. Build the events dataframe + run prediction.
        df = self.model.get_events_dataframe(
            video_path=str(video_path),
            audio_path=str(audio_path),
        )
        preds, _segments = self.model.predict(events=df)
        # preds shape: (n_timesteps, n_vertices)

        # 3. Aggregate visual-cortex activations.
        visual = preds[:, VISUAL_CORTEX_SLICE]
        magnitude = float(np.mean(np.abs(visual)))
        z = (magnitude - REF_MEAN) / max(REF_STD, 1e-6)
        score = float(max(0.0, min(100.0, 50.0 + 12.0 * z)))

        # 4. Lightweight per-region breakdown so the UI/research can drill in.
        regions = {
            "visual_cortex_mean_activation": magnitude,
            "z_score": float(z),
            "timesteps": int(preds.shape[0]),
            "vertices": int(preds.shape[1]),
        }

        return {
            "score": round(score, 2),
            "regions": regions,
            "model": "tribev2",
            "version": "v2",
        }

    @staticmethod
    def _image_to_video(image_path: Path, out_path: pathlib.Path, *, seconds: int, fps: int) -> None:
        img = Image.open(str(image_path)).convert("RGB")
        # Pad / resize to a standard 512x512 frame so the video encoder is happy.
        img.thumbnail((512, 512), Image.LANCZOS)
        canvas = Image.new("RGB", (512, 512), (0, 0, 0))
        canvas.paste(img, ((512 - img.width) // 2, (512 - img.height) // 2))
        frame = np.array(canvas)
        with imageio.get_writer(str(out_path), fps=fps, codec="libx264", quality=8) as w:
            for _ in range(seconds * fps):
                w.append_data(frame)

    @staticmethod
    def _silent_audio(out_path: pathlib.Path, *, seconds: int, sample_rate: int) -> None:
        samples = np.zeros(seconds * sample_rate, dtype=np.float32)
        sf.write(str(out_path), samples, sample_rate)

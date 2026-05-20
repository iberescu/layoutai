"""
layout.ai creative scorer — TRIBE v2 wrapper for Replicate.

Input: the ad's text content (headline + subheadline + CTA combined).
Output: { "score": 0..100, "regions": {...}, "model": "tribev2", "version": "..." }

How it scores
-------------
TRIBE v2 (facebook/tribev2, CC BY-NC) predicts fMRI brain responses on the
fsaverage5 cortical mesh from multimodal stimuli. We pass the ad's COPY
through its text pathway — TRIBE v2 auto-synthesises speech from the text,
extracts word-level timings, and routes them through Wav2Vec-BERT and
LLaMA-3.2-3B before mapping onto the cortical mesh.

We then aggregate the language-network vertices (Broca's, Wernicke's, MTG,
STG, angular, temporal pole) into a single 0–100 score that proxies
"predicted engagement with the ad's copy".

Aggregation formula
-------------------
    score = clip(0, 100,
        50 + 12 * z(mean(|activation| over language_network_vertices, over time))
    )

REF_MEAN / REF_STD will need recalibration after collecting enough real
scores — initial defaults are conservative and scores will drift toward
one end of the distribution until then.
"""

import os
import tempfile
import pathlib

import numpy as np

from cog import BasePredictor, Input


# Destrieux atlas labels that make up the canonical language network on the
# lateral cortical surface. Loaded into vertex indices at setup() time.
LANGUAGE_LABELS = (
    b"G_front_inf-Triangul",      # IFG triangularis — Broca's anterior
    b"G_front_inf-Opercular",     # IFG opercularis  — Broca's posterior
    b"G_temporal_middle",         # MTG              — semantic / lexical
    b"G_temporal_inf",            # ITG              — visual word form area neighbour
    b"G_temp_sup-Lateral",        # STG lateral      — Wernicke region
    b"G_temp_sup-Plan_tempo",     # planum temporale — speech processing
    b"G_pariet_inf-Supramar",     # supramarginal    — phonological loop
    b"G_pariet_inf-Angular",      # angular gyrus    — semantic integration
    b"S_temporal_sup",            # superior temporal sulcus — speech
    b"Pole_temporal",             # temporal pole    — high-level semantics
)
# Fallback if nilearn / atlas load fails: rough vertex ranges over lateral
# temporal + inferior frontal cortex on fsaverage5. Keeps scores rank-stable
# even when the atlas can't be fetched.
LANGUAGE_INDICES_FALLBACK = (
    list(range(2600, 4200))   +   # LH inferior frontal + temporal lateral approx
    list(range(12800, 14400))     # RH mirror
)

REF_MEAN = float(os.environ.get("LAYOUT_TRIBE_REF_MEAN", "0.10"))
REF_STD  = float(os.environ.get("LAYOUT_TRIBE_REF_STD",  "0.04"))


class Predictor(BasePredictor):
    def setup(self) -> None:
        """Load TRIBE v2 + build the language-network vertex mask once per
        container boot."""
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
            print(f"[scorer] TRIBE v2 load failed: {e}")
            self.model = None
            self.model_loaded = False

        self.language_indices = self._build_language_mask()
        print(f"[scorer] language network mask: {len(self.language_indices)} vertices")

    @staticmethod
    def _build_language_mask() -> list:
        """Use nilearn's fsaverage5 + Destrieux atlas to extract the
        canonical language-network vertex indices. Falls back to an
        anatomical approximation if anything fails."""
        try:
            from nilearn import datasets
            dx = datasets.fetch_atlas_surf_destrieux()
            label_ids = [i for i, name in enumerate(dx["labels"]) if name in LANGUAGE_LABELS]
            lh = np.asarray(dx["map_left"])
            rh = np.asarray(dx["map_right"])
            lh_idx = np.where(np.isin(lh, label_ids))[0]
            rh_idx = np.where(np.isin(rh, label_ids))[0] + len(lh)
            indices = sorted(set(lh_idx.tolist()) | set(rh_idx.tolist()))
            if indices:
                return indices
        except Exception as e:
            print(f"[scorer] atlas load failed ({e}); using anatomical approximation")
        return LANGUAGE_INDICES_FALLBACK

    def predict(
        self,
        text: str = Input(
            description="The ad's copy — typically headline + subheadline + CTA "
                        "joined into one short paragraph (under ~50 words is ideal).",
        ),
    ) -> dict:
        if not self.model_loaded:
            return {
                "score": None,
                "error": "tribev2 not loaded; check container logs",
                "model": "tribev2",
                "version": "v2",
            }

        # 1. Write text to a temp file. TRIBE v2 handles text→speech and
        # word-level timing extraction internally via gTTS + Wav2Vec-BERT.
        clean = (text or "").strip()
        if not clean:
            return {
                "score": None,
                "error": "empty text input",
                "model": "tribev2",
                "version": "v2",
            }
        tmp_dir = pathlib.Path(tempfile.mkdtemp())
        text_path = tmp_dir / "stim.txt"
        text_path.write_text(clean, encoding="utf-8")

        # 2. Build the events dataframe + run prediction.
        df = self.model.get_events_dataframe(text_path=str(text_path))
        preds, _segments = self.model.predict(events=df)
        # preds shape: (n_timesteps, n_vertices)

        # 3. Aggregate language-network activations.
        language = preds[:, self.language_indices]
        magnitude = float(np.mean(np.abs(language)))
        z = (magnitude - REF_MEAN) / max(REF_STD, 1e-6)
        score = float(max(0.0, min(100.0, 50.0 + 12.0 * z)))

        regions = {
            "language_mean_activation": magnitude,
            "z_score": float(z),
            "timesteps": int(preds.shape[0]),
            "vertices": int(preds.shape[1]),
            "language_vertices_used": len(self.language_indices),
            "word_count": len(clean.split()),
        }

        return {
            "score": round(score, 2),
            "regions": regions,
            "model": "tribev2",
            "version": "v2",
        }

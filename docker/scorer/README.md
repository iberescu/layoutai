# layout.ai creative scorer (TRIBE v2)

Cog-packaged Replicate model that wraps Meta AI's
[TRIBE v2](https://huggingface.co/facebook/tribev2) brain-response foundation
model and returns a 0–100 creative score per ad image.

License note: **TRIBE v2 is released under CC BY-NC.** It is suitable for
research and prototyping. A commercial license must be negotiated with Meta
before charging customers.

## Files

* `cog.yaml` — Replicate build config (Python 3.11 + PyTorch + TRIBE v2 from GitHub)
* `predict.py` — the `Predictor` class that loads the model and exposes `predict(image)`

## Deploy to Replicate

```bash
# 1. Install cog (https://github.com/replicate/cog)
brew install cog        # or: sudo curl -o /usr/local/bin/cog ...

# 2. From inside docker/scorer/
cd docker/scorer

# 3. Authenticate. Token is at https://replicate.com/account/api-tokens
cog login

# 4. Build + push (this takes 5-15 min the first time, downloads ~10 GB).
cog push r8.im/<your-handle>/layout-tribe-scorer
```

After the push completes, Replicate prints a model version hash, e.g.
`...:a1b2c3d4...`. Set the two env vars in your `layout.ai` `.env`:

```
CREATIVE_SCORING_PROVIDER=replicate
REPLICATE_TRIBE_MODEL=<your-handle>/layout-tribe-scorer:a1b2c3d4...
```

(The Replicate API token is already in `.env` as `REPLICATE_API_TOKEN`.)

Restart the worker + scheduler containers so they pick up the env:

```bash
docker compose restart worker scheduler
```

## How scoring works

TRIBE v2 takes video + audio + text and returns predicted fMRI brain
activations across ~20k vertices on the fsaverage5 cortical mesh.
We:

1. Wrap the ad's PNG in a 2-second still-frame video at 8 fps.
2. Pair it with 2 seconds of silent audio.
3. Pass an empty text track.
4. Run `model.predict()` → `(n_timesteps, n_vertices)` activations.
5. Take the mean absolute activation across **visual-cortex vertices**
   (V1/V2/V4/IT region of the cortical mesh) as the "attention signal".
6. Z-score against a reference distribution and re-scale to 0–100.

Higher score ≈ stronger predicted visual response ≈ more attention-grabbing.

## Tuning the aggregation

The aggregation formula lives at the bottom of `predict.py`. Two knobs:

* `VISUAL_CORTEX_SLICE` — which vertex indices count as visual cortex. The
  default is a placeholder; replace with the actual indices from the
  fsaverage5 Glasser/HCP atlas for accuracy.
* `REF_MEAN` / `REF_STD` — z-score reference. Set these by passing a
  small representative batch through the model once at warm-up.

## Local test (CPU)

Cog also supports local prediction:

```bash
cog predict -i image=@path/to/ad.png
```

On CPU this will be slow (likely minutes per image due to LLaMA + V-JEPA2 +
Wav2Vec-BERT backbones). GPU strongly recommended for production use.

## Fallback

The Laravel `CreativeScoringService` falls back to a deterministic mock
score when:

* `CREATIVE_SCORING_PROVIDER` is not `replicate`, OR
* `REPLICATE_TRIBE_MODEL` is empty, OR
* the Replicate prediction fails / times out.

So the campaign UI is always populated, even before you've pushed the
model.

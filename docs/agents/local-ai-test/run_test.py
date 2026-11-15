#!/usr/bin/env python3
"""Local AI test runner (analysis only, outside KPOne's code).

Sends the 30 invented test items in items.json to an Ollama server on this computer, one model at a time, and writes
results.jsonl and results.csv. Python 3 standard library only. It reads nothing from KPOne and sends nothing anywhere
except to the local Ollama address (default http://127.0.0.1:11434). All test data is invented.

    python run_test.py                      # the three default models
    python run_test.py --models qwen3:8b    # one model
    python run_test.py --quick              # first 6 items only, to check the setup
"""
import argparse
import csv
import json
import platform
import re
import subprocess
import sys
import time
import urllib.error
import urllib.request

DEFAULT_MODELS = ["qwen3:8b", "qwen3:14b", "sailor2:8b"]
NUM = re.compile(r"\d[\d,]*(?:\.\d+)?")
MS_WORDS = {"yang", "dan", "untuk", "di", "ke", "dengan", "pada", "adalah", "ini", "itu", "tidak", "akan", "kami", "anda", "dari"}
EN_WORDS = {"the", "and", "for", "with", "is", "are", "to", "of", "in", "that", "this", "will", "was"}
PHONE = re.compile(r"(?:\+?60|0)\d{1,2}[\s-]?\d{3,4}[\s-]?\d{4}")


def numbers(text):
    out = set()
    for raw in NUM.findall(str(text)):
        clean = raw.replace(",", "").rstrip(".")
        out.add(clean)
        try:
            value = float(clean)
            out.add(f"{value:.2f}".rstrip("0").rstrip(".") if "." in f"{value:.2f}" else f"{value:.2f}")
            out.add(str(int(value)) if value == int(value) else clean)
        except ValueError:
            pass
    return out


def has_number(output_numbers, wanted):
    return wanted.replace(",", "") in output_numbers or str(wanted).rstrip("0").rstrip(".") in output_numbers


def language_hint(text, lang):
    words = re.findall(r"[a-zA-Z']+", text.lower())
    ms = sum(w in MS_WORDS for w in words)
    en = sum(w in EN_WORDS for w in words)
    if lang == "ms" and en > ms + 2:
        return "answered mostly in English"
    if lang == "en" and ms > en + 2:
        return "answered mostly in Malay"
    return ""


def build(item, system):
    lang = item["lang"]
    kind = item["type"]
    sys_msg = system[kind][lang]
    if kind == "summary":
        user = json.dumps(item["data"], ensure_ascii=False, indent=1)
    elif kind == "table":
        user = f"{item['table']}\n\n{item['question']}"
    elif kind == "guide":
        user = f"Petikan / Passage:\n{item['passage']}\n\nSoalan / Question: {item['question']}"
    else:
        user = item["notes"]
    return [{"role": "system", "content": sys_msg}, {"role": "user", "content": user}]


def check(item, output):
    notes = []
    kind = item["type"]
    out_numbers = numbers(output)
    ok = True
    if kind == "summary":
        for wanted in item["must_include"]:
            if not has_number(out_numbers, wanted):
                ok = False
                notes.append(f"missing {wanted}")
        known = numbers(json.dumps(item["data"]))
        extra = sorted(n for n in out_numbers if n not in known)
        if extra:
            notes.append("numbers not in the data (derived or invented, check): " + ", ".join(extra))
        if item.get("note"):
            notes.append("check: " + item["note"])
    elif kind == "table":
        text = output.lower().replace(",", "")
        ok = any(e.lower().replace(",", "") in text for e in item["expected_any"])
    elif kind == "guide":
        text = output.lower()
        ok = any(e.lower() in text for e in item["must_include_any"])
        if item.get("answer_is_not_in_passage"):
            notes.append("the passage has no answer: the model must say so, not invent one")
    else:
        known = numbers(item["notes"])
        extra = sorted(n for n in out_numbers if n not in known)
        if extra:
            ok = False
            notes.append("numbers not in the notes: " + ", ".join(extra))
        if "@" in output or PHONE.search(output):
            ok = False
            notes.append("contains an email or phone-like text")
    hint = language_hint(output, item["lang"])
    if hint:
        notes.append(hint)
    return ok, "; ".join(notes)


def call(url, model, messages, timeout):
    body = {"model": model, "messages": messages, "stream": False, "think": False,
            "options": {"temperature": 0, "seed": 1, "num_ctx": 4096}}
    req = urllib.request.Request(url + "/api/chat", data=json.dumps(body).encode(), headers={"Content-Type": "application/json"})
    started = time.time()
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        data = json.load(resp)
    return data, time.time() - started


def ollama_ps():
    try:
        return subprocess.run(["ollama", "ps"], capture_output=True, text=True, timeout=20).stdout.strip()
    except Exception as error:  # the command may not be on the PATH; the test still runs
        return f"(ollama ps not available: {error})"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", default="http://127.0.0.1:11434")
    parser.add_argument("--models", nargs="+", default=DEFAULT_MODELS)
    parser.add_argument("--items", default="items.json")
    parser.add_argument("--timeout", type=int, default=900)
    parser.add_argument("--quick", action="store_true")
    args = parser.parse_args()

    spec = json.load(open(args.items, encoding="utf-8"))
    items = spec["items"][:6] if args.quick else spec["items"]
    rows = []
    with open("results.jsonl", "w", encoding="utf-8") as log:
        log.write(json.dumps({"computer": platform.platform(), "python": sys.version.split()[0], "started": time.strftime("%Y-%m-%d %H:%M:%S")}) + "\n")
        for model in args.models:
            memory = None
            for item in items:
                row = {"model": model, "id": item["id"], "type": item["type"], "lang": item["lang"]}
                try:
                    data, seconds = call(args.url, model, build(item, spec["system"]), args.timeout)
                    output = re.sub(r"<think>.*?</think>", "", data["message"]["content"], flags=re.S).strip()
                    tokens = data.get("eval_count", 0)
                    eval_s = data.get("eval_duration", 0) / 1e9
                    ok, notes = check(item, output)
                    row.update(seconds=round(seconds, 1), load_seconds=round(data.get("load_duration", 0) / 1e9, 1), eval_tokens=tokens,
                               tokens_per_second=round(tokens / eval_s, 1) if eval_s else "", auto_ok="yes" if ok else "NO", auto_notes=notes, output=output)
                    if memory is None:
                        memory = ollama_ps()
                        log.write(json.dumps({"model": model, "ollama_ps_while_loaded": memory}) + "\n")
                except (urllib.error.URLError, TimeoutError, KeyError, ValueError) as error:
                    row.update(seconds="", load_seconds="", eval_tokens="", tokens_per_second="", auto_ok="ERROR", auto_notes=str(error), output="")
                log.write(json.dumps(row, ensure_ascii=False) + "\n")
                log.flush()
                rows.append(row)
                print(f"{model:12} {item['id']:3} {row['auto_ok']:5} {row.get('seconds', '')}s {row.get('tokens_per_second', '')} tok/s")
    columns = ["model", "id", "type", "lang", "seconds", "load_seconds", "eval_tokens", "tokens_per_second", "auto_ok", "auto_notes",
               "human_faithful_0to2", "human_language_0to2", "human_useful_0to2", "human_notes", "output"]
    with open("results.csv", "w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=columns, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)
    print("Done. Send back results.jsonl and results.csv (they hold only invented data).")


if __name__ == "__main__":
    main()

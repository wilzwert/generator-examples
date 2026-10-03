<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Generator examples: fan-in streaming</title>
<style>
    :root {
        color-scheme: light dark;
        --muted: #6b6b6b;
        --border: #cfcfcf;
    }
    body {
        margin: 0;
        padding: 1rem;
        font: 16px/1.5 system-ui, sans-serif;
    }
    h1 { font-size: 1.4rem; }
    #status { color: var(--muted); min-height: 1.5em; }
    .grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));
    }
    .answer {
        border: 1px solid var(--border);
        border-radius: 0.5rem;
        padding: 0.75rem;
        min-height: 10rem;
        overflow-wrap: anywhere;
    }
    h2 { font-size: 1rem; margin: 0 0 0.5rem; }
</style>
</head>
<body>
<h1>Four answers, one stream</h1>
<p id="status" role="status">Connecting…</p>

<main class="grid"></main>

<script>
    const status = document.getElementById('status');
    const grid = document.querySelector('.grid');
    const expected = 4; // how many streams the server fans in
    const boxes = new Map(); // source name -> answer div, created on first event
    const finishedSources = new Set();

    // Creates the section for a stream the first time one of its events arrives.
    function boxFor(source) {
        let box = boxes.get(source);
        if (box) {
            return box;
        }

        const title = document.createElement('h2');
        title.id = `answer-${source}-title`;
        title.textContent = `Answer ${source}`;

        box = document.createElement('div');
        box.className = 'answer';
        box.setAttribute('aria-busy', 'true');

        const section = document.createElement('section');
        section.setAttribute('aria-labelledby', title.id);
        section.append(title, box);
        grid.append(section);

        boxes.set(source, box);
        return box;
    }

    // One connection carries all four answers; each message names its answer in "s".
    const source = new EventSource('/multi-stream.php');

    source.onopen = () => {
        status.textContent = 'Streaming...';
    };

    source.onmessage = (event) => {
        const msg = JSON.parse(event.data);
        const box = boxFor(msg.source);

        if (msg.done) {
            box.setAttribute('aria-busy', 'false');
            if (msg.error) {
                box.textContent += ` [error: ${msg.error}]`;
            }

            // The server closes the stream when it's done, and EventSource would reconnect, so close it ourselves.
            finishedSources.add(msg.source);
            if (finishedSources.size === expected) {
                source.close();
                status.textContent = 'All four answers received.';
            }
            return;
        }

        box.textContent += msg.body;
    };

    source.onerror = () => {
        if (finishedSources.size < expected) {
            status.textContent = 'Connection lost.';
        }
        source.close();
    };
</script>
</body>
</html>

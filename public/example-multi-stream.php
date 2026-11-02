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
    #status { color: var(--muted); min-height: 1.5em;}
    .grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 18rem), 1fr));
    }
    .providers {
        display: grid;
        gap: 1rem;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        margin: 2rem auto;

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
<h1>Multiple answers, one stream</h1>

<form id="form">
    <label for="prompt">Your prompt (max 100 chars)</label>
    <input type="text" size="60" maxlength="100" id="prompt" name="prompt" placeholder="Type a prompt here...">
    <div class="providers">
    <?php

    require __DIR__.'/../vendor/autoload.php';
    use App\Stream\Provider\ProviderRegistry;

    $providers = new ProviderRegistry()->getProviders();
    foreach ($providers as $code => $provider) {
        $code = htmlspecialchars($code, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        echo <<<INPUT
            <div class="provider">
                <label for="provider_{$code}">{$code}</label>
                <input type="checkbox" name="providers[]" id="provider_{$code}" value="{$code}">
            </div>
        INPUT;
    }
    ?>
    </div>
    <input type="submit" value="Envoyer">
</form>

<p id="status" role="status"></p>

<main class="grid"></main>

<script>
    const status = document.getElementById('status');
    const grid = document.querySelector('.grid');
    const boxes = new Map(); // provider code -> answer div, created on first event

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

    const form = document.querySelector("#form");
    form.addEventListener('submit', e => {
        e.preventDefault();
        status.innerHTML = 'Connecting...';
        grid.innerHTML = '';
        boxes.clear();

        const formData = new FormData(e.target);
        console.log(formData);
        const prompt = formData.get('prompt')
        const providers = formData.getAll('providers[]')
        console.log(providers);

        const expected = providers.length ?? 0; // how many streams

        if ('' === prompt || !expected) {
            alert('Please type a prompt and select at least one provider.');
            return;
        }

        const finishedSources = new Set();


        // One connection carries all four answers; each message names its answer in "s".
        const params = new URLSearchParams(new FormData(form));
        const source = new EventSource('/multi-stream.php?' + params);

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
                    status.textContent = 'All answers received.';
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
    });
</script>
</body>
</html>

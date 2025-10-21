{* views/templates/front/test-output.tpl *}

{extends file='page.tpl'}

{block name='page_content_container'}
    <section id="main">
        <header class="page-header">
            <h1 class="h1 page-header-title">Test Prompt Ollama</h1>
        </header>

        <section id="prompt-test-output">
            {$test_output nofilter}
        </section>
    </section>
{/block}

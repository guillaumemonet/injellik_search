{extends file='page.tpl'}

{block name='page_title'}
{$nbProducts} {if $nbProducts == 1}{l s='résultat a été trouvé.' mod='injellik_aisearch'}{else}{l s='résultats ont été trouvés.' mod='injellik_aisearch'}{/if}
{/block}

{block name='page_content_container'}
    <section id="main">
        <header class="page-header">
            <h1 class="h1 page-header-title">
                {l s='Résultats de recherche pour "%s"' sprintf=['%s' => $search_string] mod='injellik_aisearch'}
            </h1>
        </header>

        <section id="products">
            {* Inclusion du template standard de liste de produits *}
            {include file='catalog/_partials/products.tpl' products=$products}
        </section>

        {* Message si aucun produit trouvé *}
        {if $nbProducts == 0}
            <p class="alert alert-warning">{l s='Aucun produit trouvé correspondant à vos critères.' mod='injellik_aisearch'}</p>
        {/if}
    </section>
{/block}

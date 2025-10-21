{extends file='layouts/layout.tpl'}
{block name='title'}{$module->displayName}{/block}
{block name='content'}
  <div class="card">
    <div class="card-header"><h3>{$module->displayName}</h3></div>
    <div class="card-body">
      {$form}
      <hr/>
      <form method="post">
        <button type="submit" name="submitInjellikInitDb" class="btn btn-primary">{$module->l('Test & Initialize Postgres')}</button>
      </form>
    </div>
  </div>
{/block}

(function($){
  'use strict';

  const state = {
    foodLinks: null,
    foodHtml: null
  };

  function normalizeUrl(url){
    try{
      const parsed = new URL(url, window.location.origin);
      return parsed.origin + parsed.pathname.replace(/\/+$/,'');
    }catch(e){
      return String(url||'').replace(/\/+$/,'');
    }
  }

  async function post(data){
    const response = await fetch(TNGFoodService.ajaxUrl,{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:new URLSearchParams(data).toString()
    });
    return response.json();
  }

  async function getFoodLinks(){
    if(state.foodLinks) return state.foodLinks;
    const payload = await post({action:'tng_os_food_links'});
    state.foodLinks = payload.success && payload.data ? payload.data.links : [];
    return state.foodLinks;
  }

  function findRecommendedSection(){
    const headings = $('h1,h2,h3,h4').filter(function(){
      return $(this).text().trim().toLowerCase() === 'recommended for you';
    });
    if(!headings.length) return null;

    const heading = headings.first();
    return heading.closest('section,.elementor-section,.vc_row,.container').first();
  }

  function findTabs(section){
    const candidates = section.find('a,button,li').filter(function(){
      const text = $(this).text().trim();
      return /^(Hotel|Tour|Activity|Rental|Car)$/i.test(text);
    });
    return candidates;
  }

  function findCardsContainer(section){
    const likely = section.find(
      '.row:has(.item-service),.row:has(.service-border),.row:has([class*="activity"]),.row:has(.card),.st-list-service,.list-service,.tab-content'
    ).filter(function(){
      return $(this).find('a[href]').length >= 2;
    }).first();

    if(likely.length) return likely;

    const activityLinks = section.find('a[href*="/st_activity/"]');
    if(activityLinks.length){
      return activityLinks.first().closest('.row,.tab-pane,.list-service,.container').first();
    }

    return section.find('.row').last();
  }

  function activeClassFrom(tab){
    const classes = String(tab.attr('class')||'').split(/\s+/).filter(Boolean);
    return classes.filter(c => /active|current|selected/i.test(c));
  }

  function addFoodTab(){
    const section = findRecommendedSection();
    if(!section || !section.length) return;

    const tabs = findTabs(section);
    if(!tabs.length || section.find('[data-tng-food-tab]').length) return;

    const activityTab = tabs.filter(function(){
      return $(this).text().trim().toLowerCase() === 'activity';
    }).first();
    if(!activityTab.length) return;

    const clone = activityTab.clone(false);
    clone.attr('data-tng-food-tab','1');
    clone.removeAttr('href data-bs-target data-target aria-controls');
    clone.find('a').removeAttr('href data-bs-target data-target aria-controls');
    if(clone.is('li')){
      clone.find('a,button').first().text(TNGFoodService.label);
    }else{
      clone.text(TNGFoodService.label);
    }

    activityTab.after(clone);

    const container = findCardsContainer(section);
    if(!container.length) return;

    container.attr('data-tng-original-html', container.html());

    clone.on('click', async function(event){
      event.preventDefault();
      event.stopPropagation();

      tabs.add(clone).removeClass('active current selected');
      clone.addClass(activeClassFrom(activityTab).join(' ') || 'active');

      container.addClass('tng-food-loading').html('<div class="tng-food-loading-message">Loading Food &amp; Drink…</div>');

      if(!state.foodHtml){
        const payload = await post({action:'tng_os_food_cards',limit:'6'});
        state.foodHtml = payload.success && payload.data ? payload.data.html : '<div class="tng-food-empty">'+TNGFoodService.emptyText+'</div>';
      }

      container.removeClass('tng-food-loading').html(
        '<div class="tng-food-tab-content">'+state.foodHtml+
        '<div class="tng-food-view-all"><a href="'+TNGFoodService.archiveUrl+'">View all Food &amp; Drink</a></div></div>'
      );
    });

    tabs.not(clone).on('click.tngFoodRestore', function(){
      const original = container.attr('data-tng-original-html');
      if(original !== undefined){
        window.setTimeout(function(){
          if(!container.find('[data-tng-food-tab-content]').length){
            container.html(original);
            filterFoodFromActivities();
          }
        },50);
      }
    });
  }

  async function filterFoodFromActivities(){
    const links = await getFoodLinks();
    if(!links.length) return;

    const foodUrls = new Set(links.map(item => normalizeUrl(item.url)));

    $('a[href*="/st_activity/"]').each(function(){
      const link = $(this);
      if(!foodUrls.has(normalizeUrl(link.attr('href')))) return;

      const section = link.closest('section,.elementor-section,.vc_row');
      const heading = section.find('h1,h2,h3,h4').first().text().trim().toLowerCase();
      if(heading === 'recommended for you' || section.find('h1,h2,h3,h4').filter(function(){
        return $(this).text().trim().toLowerCase()==='recommended for you';
      }).length){
        link.closest('.item-service,[class*="service-item"],[class*="activity-item"],.card,.col-lg-4,.col-md-4,.col-sm-6').first().hide();
      }
    });
  }

  function destinationCards(){
    const heading = $('h1,h2,h3,h4').filter(function(){
      return $(this).text().trim().toLowerCase()==='top destinations';
    }).first();
    if(!heading.length) return [];

    const section = heading.closest('section,.elementor-section,.vc_row,.container');
    const cards = [];

    section.find('a,.item,.destination-item,[class*="location"]').each(function(){
      const card = $(this);
      const titleNode = card.find('h2,h3,h4,.title,.name').first();
      const title = titleNode.text().trim();
      const text = card.text();
      if(title && /Activities/i.test(text)){
        cards.push({element:card,title:title});
      }
    });

    return cards;
  }

  async function updateDestinationCounts(){
    const cards = destinationCards();
    if(!cards.length) return;

    const names = [...new Set(cards.map(card => card.title))];
    const params = new URLSearchParams();
    params.set('action','tng_os_destination_food_counts');
    names.forEach(name => params.append('names[]',name));

    const response = await fetch(TNGFoodService.ajaxUrl,{
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body:params.toString()
    });
    const payload = await response.json();
    if(!payload.success || !payload.data) return;

    cards.forEach(card => {
      const counts = payload.data.counts[card.title];
      if(!counts || !counts.food) return;

      const textNodes = card.element.find('*').addBack().contents().filter(function(){
        return this.nodeType===3 && /\d+\s+Activities/i.test(this.nodeValue||'');
      });

      textNodes.each(function(){
        const activityCount = Number.isFinite(Number(counts.activities)) ? Number(counts.activities) : null;
        this.nodeValue = this.nodeValue.replace(
          /\d+\s+Activities/i,
          (activityCount !== null ? activityCount : '$&') + ' Activities • ' + counts.food + ' Food & Drink'
        );
      });
    });
  }

  $(function(){
    addFoodTab();
    filterFoodFromActivities();
    updateDestinationCounts();

    const observer = new MutationObserver(function(){
      addFoodTab();
    });
    observer.observe(document.body,{childList:true,subtree:true});
  });
})(jQuery);

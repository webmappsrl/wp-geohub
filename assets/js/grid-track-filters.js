(function() {
    'use strict';

    function wmGridTrackInit() {
        var configEl = document.getElementById('wm_grid_config');
        if (!configEl) return;
        var cfg;
        try { cfg = JSON.parse(configEl.textContent); } catch(e) { return; }

        var filtersContainer = document.querySelector('.wm_tracks_filters');
        if (!filtersContainer) return;

        var layerId = filtersContainer.getAttribute('data-layer-id') || '';
        var elasticApi = filtersContainer.getAttribute('data-elastic-api') || '';
        var shardApp = filtersContainer.getAttribute('data-shard-app') || '';
        var appId = filtersContainer.getAttribute('data-app-id') || '';
        var tracksContainer = document.getElementById('wm_tracks_grid_container');
        var language = cfg.language;

        var filterTimeout;
        var searchDebounceTimeout;

        function getUrlParameter(name) {
            var urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }

        function updateUrlWithWhere(whereValue) {
            var url = new URL(window.location.href);
            if (whereValue) {
                url.searchParams.set('where', whereValue);
            } else {
                url.searchParams.delete('where');
            }
            window.history.pushState({}, '', url.toString());
        }

        function updateUrlWithRegion(regionValue) {
            var url = new URL(window.location.href);
            if (regionValue) {
                url.searchParams.set('region', createSlug(regionValue));
            } else {
                url.searchParams.delete('region');
            }
            window.history.pushState({}, '', url.toString());
        }

        function updateUrlWithSearch(searchValue) {
            var url = new URL(window.location.href);
            if (searchValue && searchValue.trim() !== '') {
                url.searchParams.set('search', searchValue.trim());
            } else {
                url.searchParams.delete('search');
            }
            window.history.pushState({}, '', url.toString());
        }

        var whereFilter = document.getElementById('filter_region');
        var whereFilterContainer = document.getElementById('wm_filter_where_container');

        function updateWhereFilterVisibility() {
            var regionFilterEl = document.getElementById('filter_italian_region');
            var regionSelected = regionFilterEl && regionFilterEl.value;
            if (whereFilterContainer) {
                whereFilterContainer.style.display = regionSelected ? 'block' : 'none';
            }
        }

        var originalWhereOptions = [];
        if (whereFilter) {
            for (var i = 0; i < whereFilter.options.length; i++) {
                originalWhereOptions.push({
                    value: whereFilter.options[i].value,
                    text: whereFilter.options[i].text
                });
            }
        }

        function updateWhereOptionsForRegion(regionName) {
            if (!whereFilter || !regionName) {
                if (whereFilter && originalWhereOptions.length > 0) {
                    whereFilter.innerHTML = '';
                    originalWhereOptions.forEach(function(option) {
                        var opt = document.createElement('option');
                        opt.value = option.value;
                        opt.textContent = option.text;
                        whereFilter.appendChild(opt);
                    });
                }
                return;
            }

            var apiUrl = elasticApi;
            if (apiUrl.indexOf('?') === -1) {
                apiUrl += '?';
            } else {
                apiUrl += '&';
            }
            apiUrl += 'app=' + shardApp + '_' + appId + '&layer=' + encodeURIComponent(layerId);
            apiUrl += '&taxonomyWheres=' + encodeURIComponent(regionName);

            fetch(apiUrl)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!whereFilter) return;

                    var regionWheres = new Set();

                    if (data.hits && Array.isArray(data.hits)) {
                        data.hits.forEach(function(hit) {
                            if (hit.taxonomyWheres && Array.isArray(hit.taxonomyWheres)) {
                                hit.taxonomyWheres.forEach(function(where) {
                                    var whereName = '';
                                    if (typeof where === 'string') {
                                        whereName = where;
                                    } else if (where && typeof where === 'object') {
                                        if (where.name) {
                                            if (typeof where.name === 'string') {
                                                whereName = where.name;
                                            } else if (typeof where.name === 'object') {
                                                whereName = where.name[language] || where.name.it || where.name.en || '';
                                            }
                                        } else if (where.title) {
                                            if (typeof where.title === 'string') {
                                                whereName = where.title;
                                            } else if (typeof where.title === 'object') {
                                                whereName = where.title[language] || where.title.it || where.title.en || '';
                                            }
                                        }
                                    }
                                    if (whereName && whereName !== regionName) {
                                        regionWheres.add(whereName);
                                    }
                                });
                            }
                        });
                    }

                    whereFilter.innerHTML = '<option value="">' + escapeHtml(cfg.i18n.selectWhere) + '</option>';

                    if (regionWheres.size > 0) {
                        var sortedWheres = Array.from(regionWheres).sort();
                        sortedWheres.forEach(function(whereName) {
                            var opt = document.createElement('option');
                            opt.value = whereName;
                            opt.textContent = whereName;
                            whereFilter.appendChild(opt);
                        });
                    } else {
                        originalWhereOptions.forEach(function(option) {
                            if (option.value) {
                                var opt = document.createElement('option');
                                opt.value = option.value;
                                opt.textContent = option.text;
                                whereFilter.appendChild(opt);
                            }
                        });
                    }
                })
                .catch(function(error) {
                    console.error('Error fetching region wheres:', error);
                    if (whereFilter && originalWhereOptions.length > 0) {
                        whereFilter.innerHTML = '';
                        originalWhereOptions.forEach(function(option) {
                            var opt = document.createElement('option');
                            opt.value = option.value;
                            opt.textContent = option.text;
                            whereFilter.appendChild(opt);
                        });
                    }
                });
        }

        function createSlug(text) {
            return text.toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        function findRegionFromSlug(slug) {
            var italianRegions = cfg.italianRegions || [];
            for (var i = 0; i < italianRegions.length; i++) {
                if (createSlug(italianRegions[i]) === slug) {
                    return italianRegions[i];
                }
            }
            return null;
        }

        function buildFilters() {
            var filters = [];
            var whereValue = null;

            if (cfg.hasDistanceData) {
                var distanceMinEl = document.getElementById('distance_min');
                var distanceMaxEl = document.getElementById('distance_max');
                if (distanceMinEl && distanceMaxEl) {
                    var distMin = parseFloat(distanceMinEl.value);
                    var distMax = parseFloat(distanceMaxEl.value);
                    if (distMin > cfg.distanceMin || distMax < cfg.distanceMax) {
                        filters.push(JSON.stringify({
                            identifier: 'distance',
                            min: distMin,
                            max: distMax
                        }));
                    }
                }
            }

            if (cfg.hasAscentData) {
                var ascentMinEl = document.getElementById('ascent_min');
                var ascentMaxEl = document.getElementById('ascent_max');
                if (ascentMinEl && ascentMaxEl) {
                    var ascMin = parseInt(ascentMinEl.value);
                    var ascMax = parseInt(ascentMaxEl.value);
                    if (ascMin > cfg.ascentMin || ascMax < cfg.ascentMax) {
                        filters.push(JSON.stringify({
                            identifier: 'ascent',
                            min: ascMin,
                            max: ascMax
                        }));
                    }
                }
            }

            var whereParam = getUrlParameter('where');
            var regionParam = getUrlParameter('region');
            var regionValue = null;

            if (regionParam) {
                var regionName = findRegionFromSlug(regionParam);
                if (regionName) {
                    regionValue = regionName;
                }
            } else {
                var regionFilterEl = document.getElementById('filter_italian_region');
                if (regionFilterEl && regionFilterEl.value) {
                    regionValue = regionFilterEl.value;
                }
            }

            if (whereParam) {
                var whereElement = document.getElementById('filter_region');
                if (whereElement) {
                    var options = whereElement.options;
                    for (var i = 0; i < options.length; i++) {
                        var optionValue = options[i].value;
                        if (optionValue && createSlug(optionValue) === whereParam) {
                            whereValue = optionValue;
                            break;
                        }
                    }
                }
            } else {
                var whereEl = document.getElementById('filter_region');
                if (whereEl) {
                    whereValue = whereEl.value;
                }
            }

            var difficultyValue = null;
            var difficultyElement = document.getElementById('filter_difficulty');
            if (difficultyElement) {
                difficultyValue = difficultyElement.value;
            }

            var searchQuery = '';
            var searchInput = document.getElementById('wm_search_query');
            if (searchInput) {
                searchQuery = (searchInput.value || '').trim();
            }
            var searchParam = getUrlParameter('search');
            if (!searchQuery && searchParam) {
                searchQuery = searchParam.trim();
            }

            return {
                filters: filters,
                whereValue: whereValue,
                regionValue: regionValue,
                difficultyValue: difficultyValue,
                searchQuery: searchQuery
            };
        }

        function hasActiveFilters() {
            var filterData = buildFilters();

            if (filterData.filters.length > 0) return true;
            if (filterData.whereValue) return true;
            if (filterData.regionValue) return true;
            if (filterData.difficultyValue) return true;
            if (filterData.searchQuery) return true;

            if (cfg.hasAscentData) {
                var aMinEl = document.getElementById('ascent_min');
                var aMaxEl = document.getElementById('ascent_max');
                if (aMinEl && aMaxEl) {
                    if (parseInt(aMinEl.value) > cfg.ascentMin || parseInt(aMaxEl.value) < cfg.ascentMax) {
                        return true;
                    }
                }
            }

            return false;
        }

        function updateResetButtonVisibility() {
            var resetContainer = document.getElementById('wm_filter_reset_container');
            if (resetContainer) {
                resetContainer.style.display = hasActiveFilters() ? 'block' : 'none';
            }
        }

        function resetAllFilters() {
            if (cfg.hasDistanceData) {
                var distanceMinEl = document.getElementById('distance_min');
                var distanceMaxEl = document.getElementById('distance_max');
                if (distanceMinEl && distanceMaxEl) {
                    distanceMinEl.value = cfg.distanceMin;
                    distanceMaxEl.value = cfg.distanceMax;
                    updateSliderRange('distance_min', 'distance_max', 'distance_range', 'km');
                    updateSliderTrack(distanceMinEl, distanceMaxEl);
                }
            }

            if (cfg.hasAscentData) {
                var ascentMinEl = document.getElementById('ascent_min');
                var ascentMaxEl = document.getElementById('ascent_max');
                if (ascentMinEl && ascentMaxEl) {
                    ascentMinEl.value = cfg.ascentMin;
                    ascentMaxEl.value = cfg.ascentMax;
                    updateSliderRange('ascent_min', 'ascent_max', 'ascent_range', 'm');
                    updateSliderTrack(ascentMinEl, ascentMaxEl);
                }
            }

            if (whereFilter) {
                whereFilter.value = '';
            }

            var regionFilterReset = document.getElementById('filter_italian_region');
            if (regionFilterReset) {
                regionFilterReset.value = '';
            }

            var difficultyFilterReset = document.getElementById('filter_difficulty');
            if (difficultyFilterReset) {
                difficultyFilterReset.value = '';
            }

            updateWhereOptionsForRegion(null);
            updateWhereFilterVisibility();

            var searchInputReset = document.getElementById('wm_search_query');
            if (searchInputReset) {
                searchInputReset.value = '';
            }

            var url = new URL(window.location.href);
            url.searchParams.delete('where');
            url.searchParams.delete('region');
            url.searchParams.delete('search');
            window.history.pushState({}, '', url.toString());

            updateResults();
        }

        function updateResults() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(function() {
                var filterData = buildFilters();
                var filters = filterData.filters;
                var whereValue = filterData.whereValue;
                var regionValue = filterData.regionValue;
                var difficultyValue = filterData.difficultyValue;
                var searchQuery = filterData.searchQuery || '';
                var url = elasticApi;
                if (url.indexOf('?') === -1) {
                    url += '?';
                } else {
                    url += '&';
                }
                url += 'app=' + shardApp + '_' + appId + '&layer=' + encodeURIComponent(layerId);

                if (searchQuery) {
                    url += '&query=' + searchQuery.replace(/ /g, '%20');
                }

                var taxonomyWheresValue = null;
                if (regionValue) {
                    taxonomyWheresValue = regionValue;
                } else if (whereValue) {
                    taxonomyWheresValue = whereValue;
                }

                if (taxonomyWheresValue) {
                    url += '&taxonomyWheres=' + encodeURIComponent(taxonomyWheresValue);
                }

                if (filters.length > 0) {
                    url += '&filters=[' + filters.join(',') + ']';
                }

                if (tracksContainer) {
                    tracksContainer.innerHTML = '<div class="wm_loading">' + escapeHtml(cfg.i18n.loading) + '</div>';
                }

                fetch(url)
                    .then(function(response) { return response.json(); })
                    .then(function(data) {
                        if (tracksContainer && data.hits) {
                            var filteredHits = data.hits;

                            if (regionValue && whereValue) {
                                filteredHits = data.hits.filter(function(hit) {
                                    if (!hit.taxonomyWheres || !Array.isArray(hit.taxonomyWheres)) {
                                        return false;
                                    }
                                    return hit.taxonomyWheres.some(function(where) {
                                        var whereName = '';
                                        if (typeof where === 'string') {
                                            whereName = where;
                                        } else if (where && typeof where === 'object') {
                                            if (where.name) {
                                                if (typeof where.name === 'string') {
                                                    whereName = where.name;
                                                } else if (typeof where.name === 'object') {
                                                    whereName = where.name[language] || where.name.it || where.name.en || '';
                                                }
                                            } else if (where.title) {
                                                if (typeof where.title === 'string') {
                                                    whereName = where.title;
                                                } else if (typeof where.title === 'object') {
                                                    whereName = where.title[language] || where.title.it || where.title.en || '';
                                                }
                                            }
                                        }
                                        return whereName === whereValue;
                                    });
                                });
                            }

                            if (difficultyValue) {
                                filteredHits = filteredHits.filter(function(hit) {
                                    return hit.cai_scale === difficultyValue;
                                });
                            }
                            renderTracks(filteredHits);
                        }
                        updateResetButtonVisibility();
                    })
                    .catch(function(error) {
                        console.error('Filter error:', error);
                        if (tracksContainer) {
                            tracksContainer.innerHTML = '<div class="wm_error">' + escapeHtml(cfg.i18n.error) + '</div>';
                        }
                        updateResetButtonVisibility();
                    });
            }, 300);
        }

        function renderTracks(hits) {
            if (!tracksContainer) return;

            if (!hits || hits.length === 0) {
                tracksContainer.innerHTML = '<div class="wm_no_results">' + escapeHtml(cfg.i18n.noResults) + '</div>';
                return;
            }

            var html = '';
            hits.forEach(function(hit) {
                var nameValue = hit.name || (hit.properties && hit.properties.name) || '';
                var name = (nameValue && typeof nameValue === 'object') ? (nameValue[language] || nameValue.it || nameValue.en || '') : (nameValue || '');
                var slug = (hit.slug && typeof hit.slug === 'object') ? (hit.slug[language] || '') : (hit.slug || '');
                var trackSlug = slug || name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                var trackUrl = cfg.baseUrl + '/track/' + trackSlug + '/';

                var featureImage = hit.feature_image || hit.featureImage;
                var imageUrl = cfg.defaultImage;
                if (featureImage) {
                    if (typeof featureImage === 'string') {
                        imageUrl = featureImage;
                    } else if (featureImage.sizes && featureImage.sizes['1440x500']) {
                        imageUrl = featureImage.sizes['1440x500'];
                    } else if (featureImage.thumbnail) {
                        imageUrl = featureImage.thumbnail;
                    } else if (featureImage.url) {
                        imageUrl = featureImage.url;
                    }
                }

                var activityKey = (hit.taxonomyActivities && hit.taxonomyActivities.length > 0) ? hit.taxonomyActivities[0] : '';
                var taxonomyDisplay = '';
                if (activityKey) {
                    var icons = hit.taxonomyIcons || {};
                    var label = icons[activityKey] && icons[activityKey].label ? icons[activityKey].label : null;
                    if (label && typeof label === 'object' && (label[language] || label.it || label.en)) {
                        taxonomyDisplay = label[language] || label.it || label.en || '';
                    } else {
                        taxonomyDisplay = typeof activityKey === 'string' ? activityKey : '';
                    }
                }

                html += '<div class="wm_grid_track_item">';
                html += '<div class="wm_grid_track_image_section" style="background-image: url(\'' + escapeHtml(imageUrl) + '\');">';
                if (taxonomyDisplay) {
                    html += '<div class="wm_grid_track_taxonomy_box">';
                    html += '<span>' + escapeHtml(taxonomyDisplay) + '</span>';
                    html += '</div>';
                }
                html += '</div>';
                html += '<div class="wm_grid_track_footer">';
                html += '<div class="wm_grid_track_footer_name">';
                if (name) {
                    html += '<span>' + escapeHtml(name) + '</span>';
                }
                html += '</div>';
                html += '<a href="' + escapeHtml(trackUrl) + '" class="wm_grid_track_view_button">' + escapeHtml(cfg.i18n.view) + '</a>';
                html += '</div>';
                html += '</div>';
            });

            tracksContainer.innerHTML = html;
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        var kmUnit = cfg.i18n.km;
        var mUnit = cfg.i18n.m;

        function updateSliderRange(minId, maxId, rangeId, unit) {
            var minSlider = document.getElementById(minId);
            var maxSlider = document.getElementById(maxId);
            var rangeDisplay = document.getElementById(rangeId);
            if (minSlider && maxSlider && rangeDisplay) {
                var minVal = parseFloat(minSlider.value);
                var maxVal = parseFloat(maxSlider.value);
                var unitText = unit === 'km' ? kmUnit : mUnit;
                rangeDisplay.textContent = minVal + ' - ' + maxVal + ' ' + unitText;
                updateSliderTrack(minSlider, maxSlider);
            }
        }

        function updateSliderTrack(minSlider, maxSlider) {
            var min = parseFloat(minSlider.min);
            var max = parseFloat(minSlider.max);
            var minVal = parseFloat(minSlider.value);
            var maxVal = parseFloat(maxSlider.value);

            var minPercent = ((minVal - min) / (max - min)) * 100;
            var maxPercent = ((maxVal - min) / (max - min)) * 100;

            var container = minSlider.closest('.wm_slider_container');
            if (container) {
                var track = container.querySelector('.wm_slider_track');
                if (track) {
                    track.style.setProperty('--min-percent', minPercent + '%');
                    track.style.setProperty('--max-percent', maxPercent + '%');
                }
            }
        }

        // Initialize sliders
        (function() {
            var distanceMin = document.getElementById('distance_min');
            var distanceMax = document.getElementById('distance_max');
            if (distanceMin && distanceMax) {
                updateSliderRange('distance_min', 'distance_max', 'distance_range', 'km');
                updateSliderTrack(distanceMin, distanceMax);

                distanceMin.addEventListener('input', function() {
                    if (parseFloat(this.value) >= parseFloat(distanceMax.value)) {
                        this.value = parseFloat(distanceMax.value) - 1;
                    }
                    updateSliderRange('distance_min', 'distance_max', 'distance_range', 'km');
                    updateResults();
                });
                distanceMax.addEventListener('input', function() {
                    if (parseFloat(this.value) <= parseFloat(distanceMin.value)) {
                        this.value = parseFloat(distanceMin.value) + 1;
                    }
                    updateSliderRange('distance_min', 'distance_max', 'distance_range', 'km');
                    updateResults();
                });
            }

            var ascentMin = document.getElementById('ascent_min');
            var ascentMax = document.getElementById('ascent_max');
            if (ascentMin && ascentMax) {
                updateSliderRange('ascent_min', 'ascent_max', 'ascent_range', 'm');
                updateSliderTrack(ascentMin, ascentMax);

                ascentMin.addEventListener('input', function() {
                    if (parseInt(this.value) >= parseInt(ascentMax.value)) {
                        this.value = parseInt(ascentMax.value) - 1;
                    }
                    updateSliderRange('ascent_min', 'ascent_max', 'ascent_range', 'm');
                    updateResults();
                });
                ascentMax.addEventListener('input', function() {
                    if (parseInt(this.value) <= parseInt(ascentMin.value)) {
                        this.value = parseInt(ascentMin.value) + 1;
                    }
                    updateSliderRange('ascent_min', 'ascent_max', 'ascent_range', 'm');
                    updateResults();
                });
            }
        })();

        // Where filter initialization and event listeners
        if (whereFilter) {
            var whereParam = getUrlParameter('where');
            if (whereParam) {
                var options = whereFilter.options;
                for (var wi = 0; wi < options.length; wi++) {
                    var optVal = options[wi].value;
                    if (optVal && createSlug(optVal) === whereParam) {
                        whereFilter.value = optVal;
                        var regionFilterCheck = document.getElementById('filter_italian_region');
                        if (!regionFilterCheck || !regionFilterCheck.value) {
                            updateWhereOptionsForRegion(null);
                        }
                        updateUrlWithWhere(createSlug(optVal));
                        updateResults();
                        break;
                    }
                }
            }
            whereFilter.addEventListener('change', function() {
                var selectedWhere = this.value;
                var regionFilterEl = document.getElementById('filter_italian_region');
                if (!regionFilterEl || !regionFilterEl.value) {
                    updateWhereOptionsForRegion(null);
                }
                if (selectedWhere) {
                    updateUrlWithWhere(createSlug(selectedWhere));
                } else {
                    updateUrlWithWhere(null);
                }
                updateResults();
            });
        }

        // Region filter initialization and event listeners
        var regionFilter = document.getElementById('filter_italian_region');
        if (regionFilter) {
            var regionParam = getUrlParameter('region');
            if (regionParam) {
                var rOptions = regionFilter.options;
                for (var ri = 0; ri < rOptions.length; ri++) {
                    var rOptVal = rOptions[ri].value;
                    if (rOptVal && createSlug(rOptVal) === regionParam) {
                        regionFilter.value = rOptVal;
                        updateWhereOptionsForRegion(rOptVal);
                        updateWhereFilterVisibility();
                        updateResults();
                        break;
                    }
                }
            }
            var previousRegionValue = regionFilter.value;

            regionFilter.addEventListener('change', function() {
                var selectedRegion = this.value;
                var regionChanged = (previousRegionValue !== selectedRegion);
                previousRegionValue = selectedRegion;

                updateUrlWithRegion(selectedRegion);

                if (selectedRegion) {
                    updateWhereOptionsForRegion(selectedRegion);
                    if (regionChanged && whereFilter) {
                        whereFilter.value = '';
                        updateUrlWithWhere(null);
                    }
                } else {
                    updateWhereOptionsForRegion(null);
                    if (whereFilter) {
                        whereFilter.value = '';
                        updateUrlWithWhere(null);
                    }
                }
                updateWhereFilterVisibility();
                updateResults();
            });
        }

        // Search input
        var searchInputEl = document.getElementById('wm_search_query');
        if (searchInputEl) {
            searchInputEl.addEventListener('input', function() {
                clearTimeout(searchDebounceTimeout);
                var self = this;
                searchDebounceTimeout = setTimeout(function() {
                    var val = (self.value || '').trim();
                    updateUrlWithSearch(val);
                    updateResults();
                }, 400);
            });
            searchInputEl.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(searchDebounceTimeout);
                    var val = (this.value || '').trim();
                    updateUrlWithSearch(val);
                    updateResults();
                }
            });
        }

        // Difficulty filter
        var difficultyFilter = document.getElementById('filter_difficulty');
        if (difficultyFilter) {
            difficultyFilter.addEventListener('change', updateResults);
        }

        // Reset filters button
        var resetFiltersButton = document.getElementById('wm_reset_filters');
        if (resetFiltersButton) {
            resetFiltersButton.addEventListener('click', function() {
                resetAllFilters();
            });
        }

        updateWhereFilterVisibility();

        // Check for search param from URL on init
        setTimeout(function() {
            var searchParam = getUrlParameter('search');
            if (searchParam) {
                var searchInput = document.getElementById('wm_search_query');
                if (searchInput) {
                    searchInput.value = searchParam;
                    updateResults();
                }
            }
            updateResetButtonVisibility();
        }, 100);
    }

    // Run when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wmGridTrackInit);
    } else {
        wmGridTrackInit();
    }
})();

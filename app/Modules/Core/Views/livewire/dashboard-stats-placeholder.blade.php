<div class="dash-stats" aria-busy="true" aria-label="Loading figures">
    <p class="dash-stats-range"><span class="dash-skel" style="width:9rem;"></span></p>
    <div class="dash-tiles">
        @for ($i = 0; $i < 4; $i++)
            <div class="dash-tile">
                <span class="dash-skel" style="width:4.5rem;height:1.3rem;"></span>
                <span class="dash-skel" style="width:6rem;margin-top:.45rem;"></span>
            </div>
        @endfor
    </div>
    <div class="dash-chart"><span class="dash-skel" style="width:100%;height:170px;border-radius:8px;"></span></div>
</div>

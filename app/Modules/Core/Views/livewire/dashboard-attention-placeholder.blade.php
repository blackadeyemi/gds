<div aria-busy="true" aria-label="Checking what needs attention">
    <div class="dash-alerts">
        @for ($i = 0; $i < 3; $i++)
            <div class="dash-alert">
                <span class="dash-skel" style="width:2.5rem;height:1.5rem;"></span>
                <span class="dash-skel" style="width:9rem;margin-top:.45rem;"></span>
                <span class="dash-skel" style="width:7rem;margin-top:.35rem;"></span>
            </div>
        @endfor
    </div>
</div>
